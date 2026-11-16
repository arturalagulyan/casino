<?php

namespace App\Services\GamePlay\NetEnt;

/**
 * NetEnt servlet replies: flat `key=value&…` bodies.
 */
final class NetEntReply
{
    /**
     * Rewrite a body field by field, keeping every other pair byte for byte;
     * `$fn` returns the new value, or null to keep it.
     *
     * @param  callable(string, string): ?string  $fn
     */
    public static function patch(string $reply, callable $fn): string
    {
        $lead = str_starts_with($reply, '&') ? '&' : '';   // table games' replies open with `&`
        $out = [];
        foreach (explode('&', trim($reply)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $new = $fn(urldecode($k), urldecode($v));
            $out[] = $new === null ? $pair : $k.'='.self::value($new);
        }

        return $lead.implode('&', $out);
    }

    /**
     * A body from fields; table games' replies open with `&` (`$lead`).
     *
     * @param  array<string, string|int|float>  $fields
     */
    public static function build(array $fields, string $lead = ''): string
    {
        return $lead.implode('&', array_map(fn ($k, $v) => $k.'='.self::value((string) $v), array_keys($fields), $fields));
    }

    /**
     * A value as NetEnt writes it: raw, but for what would break the format.
     * Its clients decode with decodeURI, which leaves `%2C`, `%2F`, `%3A` as
     * they are — encoded commas turned `DOUBLE,HIT,STAND` into one action.
     */
    public static function value(string $v): string
    {
        return strtr($v, ['%' => '%25', '&' => '%26', '=' => '%3D', "\n" => '%0A']);
    }

    /** A money amount as NetEnt writes one: `3.6`, `0.03`, `0`. */
    public static function amount(float $amount): string
    {
        return rtrim(rtrim(sprintf('%.2f', $amount), '0'), '.') ?: '0';
    }
}
