<?php

namespace App\Services\GamePlay\PlayNGo;

use RuntimeException;

/**
 * The little of MessagePack (and SignalR's length-prefixed framing of it)
 * Play'n GO's game hub uses: maps, arrays, strings, binaries, ints, nil, bools.
 */
final class MessagePack
{
    /** Decode every length-prefixed SignalR message in one binary WebSocket frame. */
    public static function frames(string $binary): array
    {
        $out = [];
        $i = 0;
        while ($i < strlen($binary)) {
            $len = 0;
            $shift = 0;
            do {
                $byte = ord($binary[$i++]);
                $len |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte & 0x80);
            $msg = substr($binary, $i, $len);
            $i += $len;
            $j = 0;
            $out[] = self::decode($msg, $j);
        }

        return $out;
    }

    /** Frame encoded messages: each prefixed with its varint length. */
    public static function frame(string ...$messages): string
    {
        return implode('', array_map(fn ($m) => self::varint(strlen($m)).$m, $messages));
    }

    public static function varint(int $n): string
    {
        $out = '';
        do {
            $byte = $n & 0x7F;
            $n >>= 7;
            $out .= chr($byte | ($n ? 0x80 : 0));
        } while ($n);

        return $out;
    }

    public static function str(string $s): string
    {
        $l = strlen($s);

        return match (true) {
            $l < 32 => chr(0xA0 | $l),
            $l < 256 => "\xd9".chr($l),
            $l < 65536 => "\xda".pack('n', $l),
            default => "\xdb".pack('N', $l),
        }.$s;
    }

    /** Encode a value (list → array, assoc → map, string, int, bool, null). */
    public static function encode(mixed $v): string
    {
        if ($v === null) {
            return "\xc0";
        }
        if (is_bool($v)) {
            return $v ? "\xc3" : "\xc2";
        }
        if (is_int($v)) {
            return match (true) {
                $v >= 0 && $v < 128 => chr($v),
                $v < 0 && $v >= -32 => chr(256 + $v),
                $v >= 0 && $v < 65536 => "\xcd".pack('n', $v),
                $v >= 0 && $v < 4294967296 => "\xce".pack('N', $v),
                default => "\xd3".pack('J', $v),
            };
        }
        if (is_string($v)) {
            return self::str($v);
        }
        if (is_array($v)) {
            $n = count($v);
            if (array_is_list($v)) {
                return ($n < 16 ? chr(0x90 | $n) : "\xdc".pack('n', $n)).implode('', array_map([self::class, 'encode'], $v));
            }
            $out = $n < 16 ? chr(0x80 | $n) : "\xde".pack('n', $n);
            foreach ($v as $k => $x) {
                $out .= self::encode($k).self::encode($x);
            }

            return $out;
        }

        throw new RuntimeException('MessagePack: unsupported '.get_debug_type($v));
    }

    public static function decode(string $b, int &$i): mixed
    {
        $c = ord($b[$i++]);
        if ($c <= 0x7F) {
            return $c;
        }
        if ($c >= 0xE0) {
            return $c - 256;
        }
        if (($c & 0xF0) === 0x80) {
            return self::map($b, $i, $c & 0x0F);
        }
        if (($c & 0xF0) === 0x90) {
            return self::list($b, $i, $c & 0x0F);
        }
        if (($c & 0xE0) === 0xA0) {
            return self::bytes($b, $i, $c & 0x1F);
        }

        return match ($c) {
            0xC0 => null,
            0xC2 => false,
            0xC3 => true,
            0xC4, 0xD9 => self::bytes($b, $i, self::uint($b, $i, 1)),
            0xC5, 0xDA => self::bytes($b, $i, self::uint($b, $i, 2)),
            0xC6, 0xDB => self::bytes($b, $i, self::uint($b, $i, 4)),
            0xCC => self::uint($b, $i, 1),
            0xCD => self::uint($b, $i, 2),
            0xCE => self::uint($b, $i, 4),
            0xCF => self::uint($b, $i, 8),
            0xD0 => (fn ($v) => $v > 127 ? $v - 256 : $v)(self::uint($b, $i, 1)),
            0xD1 => (fn ($v) => $v > 32767 ? $v - 65536 : $v)(self::uint($b, $i, 2)),
            0xD2 => unpack('l', strrev(self::bytes($b, $i, 4)))[1],
            0xD3 => unpack('q', strrev(self::bytes($b, $i, 8)))[1],
            0xDC => self::list($b, $i, self::uint($b, $i, 2)),
            0xDD => self::list($b, $i, self::uint($b, $i, 4)),
            0xDE => self::map($b, $i, self::uint($b, $i, 2)),
            0xDF => self::map($b, $i, self::uint($b, $i, 4)),
            default => throw new RuntimeException(sprintf('MessagePack: type 0x%02x at %d', $c, $i - 1)),
        };
    }

    private static function uint(string $b, int &$i, int $n): int
    {
        $v = 0;
        for ($k = 0; $k < $n; $k++) {
            $v = ($v << 8) | ord($b[$i++]);
        }

        return $v;
    }

    private static function bytes(string $b, int &$i, int $n): string
    {
        $s = substr($b, $i, $n);
        $i += $n;

        return $s;
    }

    private static function list(string $b, int &$i, int $n): array
    {
        $o = [];
        while ($n--) {
            $o[] = self::decode($b, $i);
        }

        return $o;
    }

    private static function map(string $b, int &$i, int $n): array
    {
        $o = [];
        while ($n--) {
            $k = self::decode($b, $i);
            $o[$k] = self::decode($b, $i);
        }

        return $o;
    }
}
