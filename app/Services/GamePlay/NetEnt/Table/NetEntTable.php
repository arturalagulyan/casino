<?php

namespace App\Services\GamePlay\NetEnt\Table;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\NetEnt\NetEntReply;

/**
 * What NetEnt's table games share: the recorded init with our balance and
 * currency, limits read off it, cents and errors.
 */
final class NetEntTable
{
    /** The recorded init reply, made ours. */
    public static function init(GameContext $context, string $init): string
    {
        return NetEntReply::patch($init, fn (string $k) => match ($k) {
            'credit' => (string) self::cents($context->balance()),
            'playercurrencyiso', 'jackpotcurrencyiso' => $context->currency->value,
            'playercurrency', 'jackpotcurrency' => '',
            // the device lists sit in our mirror: <root>/gameclient_html/… from <root>/<game>/game/
            'staticsharedurl' => '../../gameclient_html/devicedetection/current',
            'playforfun' => 'false',
            'restore' => 'false',
            default => null,
        });
    }

    /** @return array<string, int> the init's whole-number fields (limits, …) */
    public static function numbers(string $init): array
    {
        $out = [];
        foreach (explode('&', $init) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($k !== '' && ctype_digit($v)) {
                $out[urldecode($k)] = (int) $v;
            }
        }

        return $out;
    }

    public static function error(string $action, string $message): string
    {
        return NetEntReply::build(['clientaction' => $action, 'errorcode' => '1', 'errormessage' => $message]);
    }

    /** What the bank can still pay, cents — no limit (demo play: INF) as PHP_INT_MAX. */
    public static function bankCents(GameContext $context): int
    {
        $bank = $context->bankAvailable();

        return is_finite($bank) && $bank * 100 < PHP_INT_MAX ? (int) floor($bank * 100) : PHP_INT_MAX;
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
