<?php

namespace App\Services\GamePlay\PragmaticPlay;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Builds a Pragmatic Play game's client bundle from our own legacy front-ends
 * (`games-frontend/<Name>/gs2c/…` — the desktop build — and `<Name>M/gs2c/…`,
 * whose `common/games-html5/games/vs/<symbol>/mobile/` it adds), found by the
 * game symbol (vs20fruitsw). The bundle keeps the legacy hierarchy:
 *
 *   gs2c/html5Game.html                             the page (bundle entry), its launch
 *                                                   config taken from `window.PRG` at load time
 *   gs2c/common/js/…, common/games-html5/operator_logos/…
 *   gs2c/common/games-html5/games/vs/<symbol>/desktop|mobile/…
 *
 * The spins are Betboy's recorded rounds ({@see PragmaticLiveClient}); only
 * the client is ours.
 */
class PragmaticLegacyBundle
{
    /** @var array<string, list<string>>|null symbol → legacy folders */
    private ?array $index = null;

    /** @return list<string> legacy folders (desktop first) carrying the game `$symbol` */
    public function folders(string $symbol): array
    {
        if ($this->index === null) {
            $this->index = [];
            $root = (string) config('legacy.games_frontend_path');
            foreach (File::glob("{$root}/*/gs2c/common/games-html5/games/vs/*", GLOB_ONLYDIR) as $dir) {
                $this->index[basename($dir)][] = (string) preg_replace('#/gs2c/.*$#', '', $dir);
            }
            foreach ($this->index as &$dirs) {
                usort($dirs, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));   // SweetBonanza before SweetBonanzaM
            }
        }

        return $this->index[$symbol] ?? [];
    }

    /**
     * @return array{entry: string, datapath: string, folder: string, builds: list<string>, files: int, bytes: int, languages: list<string>}
     */
    public function build(string $symbol, string $outDir): array
    {
        $folders = $this->folders($symbol);
        $rel = "gs2c/common/games-html5/games/vs/{$symbol}";
        $desktop = null;
        foreach ($folders as $dir) {
            if (is_file("{$dir}/gs2c/html5Game.html") && is_dir("{$dir}/{$rel}/desktop")) {
                $desktop = $dir;
                break;
            }
        }
        if ($desktop === null) {
            throw new RuntimeException("No legacy Pragmatic client (desktop) for {$symbol}");
        }
        File::deleteDirectory($outDir);
        File::copyDirectory("{$desktop}/gs2c", "{$outDir}/gs2c");
        $builds = ['desktop'];
        foreach ($folders as $dir) {
            foreach (['mobile', 'desktop'] as $build) {
                if (! in_array($build, $builds, true) && is_dir("{$dir}/{$rel}/{$build}")) {
                    File::copyDirectory("{$dir}/{$rel}/{$build}", "{$outDir}/{$rel}/{$build}");
                    $builds[] = $build;
                }
            }
        }
        foreach (['v3', 'saveSettings.do', 'stats.do', 'announcements'] as $stub) {   // the legacy server's canned replies
            File::deleteDirectory("{$outDir}/gs2c/{$stub}");
            File::delete("{$outDir}/gs2c/{$stub}");
        }
        File::put("{$outDir}/gs2c/html5Game.html", $this->entry((string) File::get("{$desktop}/gs2c/html5Game.html"), basename($desktop)));
        foreach ($builds as $build) {
            $this->unpatch("{$outDir}/{$rel}/{$build}/build.js");
        }
        $files = File::allFiles($outDir, true);

        return [
            'entry' => 'gs2c/html5Game.html',
            'datapath' => $rel,
            'folder' => basename($desktop),
            'builds' => $builds,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'languages' => ['en'],
        ];
    }

    /**
     * The legacy page's launch config, without the URLs of the legacy server.
     *
     * @return array<string, mixed>
     */
    public static function config(string $html): array
    {
        if (! preg_match("#gameConfig:\s*'(\{.*?\})',\s*\n\s*mgckey:#s", $html, $m)) {
            throw new RuntimeException('Not a Pragmatic legacy page (no gameConfig)');
        }
        $json = str_replace("'+location.hostname+'", 'localhost', $m[1]);
        $json = (string) preg_replace("#'\+\w+\+'#", '', $json);   // '+Curr+'
        $cfg = json_decode($json, true);
        if (! is_array($cfg)) {
            throw new RuntimeException('The legacy page\'s gameConfig is not JSON');
        }

        return array_diff_key($cfg, array_flip(['datapath', 'datapath_alternative', 'gameService', 'mgckey', 'sessionKey', 'statisticsURL',
            'REGULATION', 'LOGOUT', 'RELOAD_JACKPOT', 'RELOAD_BALANCE', 'SETTINGS', 'CLOSE_GAME', 'HISTORY', 'currency', 'currencyOriginal', 'lang']));
    }

    /**
     * The legacy platform rewrote the client's request layer
     * (ResourceRequest.SendRequest) to post gameService / saveSettings.do /
     * reloadBalance.do to its own `/game/<Code>/server?sessionId=…`; put
     * Pragmatic's back — the URLs the launch config gives.
     */
    private function unpatch(string $file): void
    {
        if (! is_file($file)) {
            return;
        }
        $js = (string) File::get($file);
        $fixed = (string) preg_replace(
            '#var tmpReq\s*=\s*this\.url\.split\("/"\);.*?req\.open\(this\.method,\s*this\.url\s*\+\s*\'\?sessionId=\'\s*\+\s*sessionStorage\.getItem\(\'sessionId\'\),\s*true\);#s',
            'req.open(this.method, this.url, true);', $js, 1, $n);
        if ($n !== 1 && str_contains($js, "'?sessionId='")) {
            throw new RuntimeException("Unknown legacy request patch in {$file}");
        }
        File::put($file, $fixed);
    }

    /** The page with its launch config from `window.PRG`, its script paths relative, the legacy exit link gone. */
    private function entry(string $html, string $folder): string
    {
        $html = str_replace("\"/games/{$folder}/gs2c/common/js/", '"common/js/', $html);
        $html = (string) preg_replace("#gameConfig:\s*'\{.*?\}',(\s*\n\s*)mgckey:\s*\"[^\"]*\",#s",
            'gameConfig: JSON.stringify(window.PRG && window.PRG.config || {}),$1mgckey: (window.PRG && window.PRG.config && window.PRG.config.mgckey) || "",', $html, 1, $n);
        if ($n !== 1) {
            throw new RuntimeException("The legacy page of {$folder} has no gameConfig / mgckey to make ours");
        }
        $html = (string) preg_replace('#lobbyUrl:\s*"[^"]*"#', 'lobbyUrl: ""', $html, 1);
        $html = (string) preg_replace('#<a target="_self" onclick="sessionStorage\.exitGame[^>]*>.*?</a>#s', '', $html, 1);

        return $html;
    }
}
