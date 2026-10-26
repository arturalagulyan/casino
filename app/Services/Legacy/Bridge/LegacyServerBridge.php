<?php

namespace App\Services\Legacy\Bridge;

use App\Services\GamePlay\GameContext;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Runs a legacy VanguardLTE game package's own backend (`Server.php` +
 * `SlotSettings.php` from games-backend) inside our game server — for games
 * whose rules live only there (Amatic blackjack, card poker…).
 *
 * The package is compiled once into a cached PHP file:
 *  - SlotSettings keeps the package's game methods (GetScore, FormatBox…)
 *    but extends {@see BridgedSlotSettings} for everything that touched the
 *    old database (balance, bank, game data, log), its constructor dropped;
 *  - Server.php's request body (inside its DB transaction / try) becomes a
 *    method: `new SlotSettings(...)` → the bridged settings, the request body
 *    → our frame, `exit(...)` / `echo ...` → return.
 * Recompiled whenever the package files change.
 */
class LegacyServerBridge
{
    /** SlotSettings methods replaced by the bridge. */
    private const array BRIDGED = [
        '__construct', 'is_active', 'SetGameData', 'GetGameData', 'HasGameData', 'SaveGameData',
        'SetGameDataStatic', 'GetGameDataStatic', 'HasGameDataStatic', 'SaveGameDataStatic',
        'GetBalance', 'SetBalance', 'GetBank', 'SetBank', 'GetPercent', 'GetCountBalanceUser',
        'UpdateJackpots', 'SaveLogReport', 'InternalError', 'InternalErrorSilent', 'HexFormat', 'GetHistory',
    ];

    /** Run one client frame (`A/u…`) through the package; returns the legacy response. */
    public function run(GameContext $ctx, string $package, string $gameData): string
    {
        $ns = $this->load($package);
        $settingsClass = $ns.'\\SlotSettings';
        $serverClass = $ns.'\\Server';
        $settings = new $settingsClass($ctx, $package);
        $server = new $serverClass($settings, ['gameData' => $gameData, 'slotEvent' => strtok($gameData, ',')]);
        // the legacy code leans on PHP's old leniency (undefined keys, string
        // maths…) — run it the way the legacy platform did, notices ignored
        set_error_handler(fn (int $no) => in_array($no, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_NOTICE, E_USER_DEPRECATED], true));
        try {
            $response = (string) $server->run();
        } finally {
            restore_error_handler();
        }
        $settings->SaveGameData();

        return $response;
    }

    /**
     * Compile `$package` into storage now (the import does this, so a game
     * server without the legacy folder mounted can still run it).
     */
    public function prepare(string $package): void
    {
        $this->load($package);
    }

    private function load(string $package): string
    {
        $ns = 'App\\LegacyBridge\\'.preg_replace('/\W/', '', $package);
        if (class_exists($ns.'\\Server', false)) {
            return $ns;
        }
        $dir = rtrim((string) config('legacy.games_backend_path'), '/').'/'.$package;
        if (! is_file("{$dir}/Server.php") || ! is_file("{$dir}/SlotSettings.php")) {
            // no legacy folder here (e.g. the game-socket container): the copy compiled at import
            $compiled = glob(storage_path("framework/legacy-bridge/{$package}-*.php")) ?: [];
            usort($compiled, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            if ($compiled === []) {
                throw new RuntimeException("Legacy package {$package} not found in {$dir} and never compiled");
            }
            require_once $compiled[0];

            return $ns;
        }
        $hash = md5((string) file_get_contents("{$dir}/Server.php").file_get_contents("{$dir}/SlotSettings.php").filemtime(__FILE__));
        $cache = storage_path("framework/legacy-bridge/{$package}-{$hash}.php");
        if (! is_file($cache)) {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache, $this->compile($ns, (string) file_get_contents("{$dir}/Server.php"), (string) file_get_contents("{$dir}/SlotSettings.php")));
        }
        require_once $cache;

        return $ns;
    }

    public function compile(string $ns, string $server, string $settings): string
    {
        $methods = '';
        foreach ($this->methods($settings) as $name => $code) {
            if (! in_array($name, self::BRIDGED, true)) {
                $methods .= "\n    ".$code."\n";
            }
        }

        $body = $this->requestBody($server);
        // the old platform's licence check
        $body = (string) preg_replace('/\$checked\s*=\s*new\s+\\\\VanguardLTE\\\\Lib\\\\LicenseDK\(\);.*?exit\s*\(\s*\$response\s*\)\s*;\s*\}/s', '', $body, 1);
        // the login check (its block ends `exit( $response ); }` — a `}` inside its JSON string must not end the match)
        $body = preg_replace('/\$userId\s*=\s*\\\\Auth::id\(\);\s*if\s*\(\s*\$userId\s*==\s*null\s*\)\s*\{.*?exit\s*\(\s*\$response\s*\)\s*;\s*\}/s', '', $body, 1);
        $body = preg_replace('/\$slotSettings\s*=\s*new\s+SlotSettings\s*\([^;]*\);/', '$slotSettings = $this->settings;', (string) $body);
        $body = preg_replace('/\$postData\s*=\s*json_decode\(\s*trim\(\s*file_get_contents\(\s*\'php:\/\/input\'\s*\)\s*\)\s*,\s*true\s*\);/', '$postData = $this->postData;', (string) $body);
        $body = preg_replace('/\bexit\s*\((.*?)\)\s*;/s', 'return $1;', (string) $body);
        $body = preg_replace('/\becho\s+([^;]+);/', 'return $1;', (string) $body);
        $methods = str_replace('(double)', '(float)', $methods);
        $body = str_replace('(double)', '(float)', (string) $body);

        return "<?php\n\nnamespace {$ns};\n\n"
            ."class SlotSettings extends \\App\\Services\\Legacy\\Bridge\\BridgedSlotSettings\n{\n{$methods}\n}\n\n"
            ."class Server\n{\n    public function __construct(public \$settings, public array \$postData) {}\n\n"
            ."    public function run()\n    {\n        \$response = '';\n{$body}\n        return \$response;\n    }\n}\n";
    }

    /**
     * Method source by name, from a class body (tokenizer brace matching).
     *
     * @return array<string, string>
     */
    private function methods(string $php): array
    {
        $tokens = token_get_all($php);
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            // method name
            $j = $i + 1;
            while ($j < $n && ! (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)) {
                $j++;
            }
            $name = $tokens[$j][1] ?? '';
            // start at the modifiers before `function`
            $start = $i;
            while ($start > 0 && (is_array($tokens[$start - 1]) && in_array($tokens[$start - 1][0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_WHITESPACE], true))) {
                $start--;
            }
            [$end] = $this->block($tokens, $j);
            $code = '';
            for ($k = $start; $k <= $end; $k++) {
                $code .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            }
            $out[$name] = trim($code);
            $i = $end;
        }

        return $out;
    }

    /** The code inside Server.php's `try { … }` (the request handling). */
    private function requestBody(string $php): string
    {
        $tokens = token_get_all($php);
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_TRY) {
                [$end, $open] = $this->block($tokens, $i);
                $code = '';
                for ($k = $open + 1; $k < $end; $k++) {
                    $code .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                }

                return $code;
            }
        }
        throw new RuntimeException('Legacy Server.php has no try block');
    }

    /**
     * From token $from, the first `{` and its matching `}`.
     *
     * @return array{0: int, 1: int} [end index, open index]
     */
    private function block(array $tokens, int $from): array
    {
        $depth = 0;
        $open = -1;
        $n = count($tokens);
        for ($k = $from; $k < $n; $k++) {
            $t = $tokens[$k];
            $s = is_array($t) ? $t[1] : $t;
            if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $open = $open < 0 ? $k : $open;
            } elseif ($s === '}') {
                $depth--;
                if ($depth === 0 && $open >= 0) {
                    return [$k, $open];
                }
            }
        }
        throw new RuntimeException('Unbalanced braces in legacy source');
    }
}
