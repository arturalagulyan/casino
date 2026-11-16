<?php

namespace App\Services\GamePlay\NetEnt;

use App\Enums\Currency;

/**
 * NetEnt's money-format files, for a currency a game's bundle lacks. The
 * clients fetch the player's currency from their static host and stop with a
 * connection error when it is missing — and NetEnt never had some at all
 * (ALL, the Albanian lek). Same shapes as NetEnt's own:
 *
 *   <root>/currencies/<ISO>.json            html clients (nth/…)
 *   <root>/currencies/<iso>/moneyformat.xml touch clients (ntt/…)
 */
final class NetEntCurrencyFile
{
    /** The file for a requested bundle path, or null when it is no currency file. */
    public static function for(string $path): ?array
    {
        if (preg_match('#(^|/)currencies/([A-Za-z]{3,4})\.json$#', $path, $m)) {
            return ['body' => self::json($m[2]), 'type' => 'application/json'];
        }
        if (preg_match('#(^|/)currencies/([a-z]{3,4})/moneyformat\.xml$#i', $path, $m)) {
            return ['body' => self::xml($m[2]), 'type' => 'application/xml'];
        }

        return null;
    }

    /** @return array{iso: string, symbol: string, decimals: int} */
    private static function format(string $iso): array
    {
        $currency = Currency::tryFrom(strtoupper($iso)) ?? Currency::tryFrom($iso);

        return ['iso' => $currency->value ?? strtoupper($iso), 'symbol' => $currency?->symbol() ?? strtoupper($iso), 'decimals' => $currency?->decimals() ?? 2];
    }

    public static function json(string $iso): string
    {
        $f = self::format($iso);

        return (string) json_encode(['iso' => $f['iso'], 'symbol' => $f['symbol'], 'centChar' => 'c', 'decimals' => $f['decimals'],
            'decimalDivider' => '.', 'thousandsDivider' => ',', 'showCurrencyChar' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public static function xml(string $iso): string
    {
        $f = self::format($iso);
        $symbol = htmlspecialchars($f['symbol'], ENT_XML1);

        return "<moneyformats>\n    <moneyformat>\n        <dividers thousands=\",\" decimal=\".\"/>\n        <strings>\n"
            ."            <curchar after=\"false\">{$symbol}</curchar>\n            <centchar usecent=\"false\" after=\"false\">c</centchar>\n"
            ."        </strings>\n        <iso>{$f['iso']}</iso>\n        <decimals>{$f['decimals']}</decimals>\n"
            ."        <showcurrencychar>true</showcurrencychar>\n    </moneyformat>\n</moneyformats>\n";
    }
}
