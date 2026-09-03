<?php

namespace App\Services\GamePlay;

use App\Services\Legacy\LegacyGameReader;
use Illuminate\Support\Str;

/**
 * Picks the real playable entry HTML out of a game bundle's file list.
 *
 * The legacy front-ends share no single convention: EGT / Gamomat ship a root
 * `index.html`, Amatic nests it under `amarent/`, Playtech under `<abbr>/`,
 * Pragmatic "gs2c" games under `gs2c/html5Game.html`, NetGame under
 * `app/<slug>/`, and NetEnt uses a `.xhtml`. Every bundle also carries decoys —
 * browser-check stubs, GWT platform loaders, rules templates, splash screens —
 * that the previous "first .html by depth" heuristic happily picked (Wazdan →
 * `help.html`, NetGame → `tpl/browserChecker/*.html`, …).
 *
 * This class encodes the family rules + the decoy blocklist so bundle
 * registration ({@see BundleManager}), the legacy reader
 * ({@see LegacyGameReader}) and the `bundles:reresolve`
 * command all agree on one answer.
 */
class BundleEntryResolver
{
    /**
     * Provider / category tokens that trail a legacy game code
     * (ActionMoney**EGT**, AgeOfEgypt**PT**). Longest-first so "DXGT" is stripped
     * before "GT". Reused by game-title normalisation.
     *
     * @var list<string>
     */
    public const array CODE_SUFFIXES = [
        'DXGTM', 'JPPTM', 'DXGT', 'JPPT', 'MHPT', 'JPSW', 'QSW', 'BGT',
        'PGT', 'PGD', 'CQ9', 'PLP', 'VEG',
        'PTM', 'GTM', 'WDM', 'PMM', 'XAM',
        'EGT', 'ISB', 'NET',
        'GT', 'PT', 'AM', 'KA', 'GM', 'WD', 'MN', 'CT', 'VP', 'VS', 'NG', 'AT',
        'IG', 'SW', 'PM', 'DX', 'BS', 'PG', 'CL', 'SG', 'GV', 'SP', 'RS', 'PP', 'IB',
    ];

    /** Path fragments that are never a real game entry (matched case-insensitively). */
    private const array DECOY_PATTERNS = [
        'browserchecker', 'startscreen', 'poswarning', 'splashscreen', 'splash/',
        'gameservice', 'iframedview', 'sslobby', 'gcmwrapper', 'browsercheck',
        'announcements/', 'rules/templates/', 'gamerules/templates/',
        '/paytable/', 'paytable_', '/gamerules/', '/gamehelp/', '/rules/',
        '/tpl/', 'tpl/', '/loader/', 'loader/', 'platform/', 'preloader/',
    ];

    private const array HTML_EXTENSIONS = ['html', 'htm', 'xhtml'];

    /** Preferred basenames for the generic fallback, best first. */
    private const array GENERIC_BASENAMES = [
        'index.html', 'index.htm', 'index.xhtml',
        'game.html', 'main.html', 'default.html', 'app.html',
    ];

    /**
     * @param  list<string>  $names  bundle-root-relative file paths (forward slashes)
     * @param  string|null  $code  the game code, used to pick a provider family
     * @param  string|null  $explicit  an operator-chosen entry that wins if present
     */
    public function resolve(array $names, ?string $code = null, ?string $explicit = null): ?string
    {
        $names = array_map(fn (string $n) => ltrim(str_replace('\\', '/', $n), '/'), $names);

        if ($explicit !== null && $explicit !== '' && in_array($explicit, $names, true)) {
            return $explicit;
        }

        $html = array_values(array_filter(
            $names,
            fn (string $n) => in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), self::HTML_EXTENSIONS, true),
        ));

        if ($html === []) {
            return null;
        }

        // Every HTML file is a decoy (browser-check stub, paytable page, GWT
        // loader) → the real entry was server-generated and isn't in the bundle.
        // Better to report "no entry" than to serve a paytable page.
        $clean = array_values(array_filter($html, fn (string $n) => ! $this->isDecoy($n)));
        if ($clean === []) {
            return null;
        }

        // A couple of nested entries are identifiable by their exact path
        // regardless of the code suffix (Pragmatic "gs2c" games, mostly).
        foreach (['gs2c/html5Game.html', 'gs2c/gameService.html'] as $known) {
            if (in_array($known, $clean, true)) {
                return $known;
            }
        }

        if (($familyEntry = $this->byFamily($clean, $code)) !== null) {
            return $familyEntry;
        }

        return $this->generic($clean);
    }

    private function isDecoy(string $name): bool
    {
        $lower = strtolower($name);

        if (preg_match('#(^|/)help\.html$#', $lower) === 1) {
            return true;
        }
        // GWT cache fragments: "<hash>.cache.html"
        if (str_ends_with($lower, '.cache.html')) {
            return true;
        }

        foreach (self::DECOY_PATTERNS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $html */
    private function byFamily(array $html, ?string $code): ?string
    {
        $suffix = $this->suffixOf($code);

        return match ($suffix) {
            'AM', 'XAM' => $this->firstLike($html, '#(^|/)amarent/index\.html?$#i')
                ?? $this->firstLike($html, '#(^|/)amarent/.+\.html?$#i'),

            'PT', 'PTM', 'JPPT', 'JPPTM', 'MHPT' => $this->shallowest(array_filter(
                $html,
                fn (string $n) => preg_match('#^[^/]+/index\.html?$#i', $n) === 1,
            )),

            'NG' => $this->firstLike($html, '#(^|/)app/[^/]+/index\.html?$#i'),

            'WD', 'WDM' => $this->firstLike($html, '#(^|/)wazdan[^/]*/index\.html?$#i')
                ?? $this->firstLike($html, '#^[^/]+/index\.html?$#i'),

            'CT' => $this->pickByFolder($html, '#(^|/)latest-stable/([^/]+)/app\.html$#i', $code),

            'NET' => $this->firstLike($html, '#(^|/)games/[^/]+/game/[^/]+\.xhtml$#i')
                ?? $this->firstLike($html, '#\.xhtml$#i'),

            'ISB' => $this->firstLike($html, '#^pulse_[^/]+\.html$#i')
                ?? $this->firstLike($html, '#(^|/)pulse_[^/]+\.html$#i'),

            default => null,
        };
    }

    /** @param list<string> $html */
    private function generic(array $html): ?string
    {
        foreach (self::GENERIC_BASENAMES as $basename) {
            $matches = array_filter($html, fn (string $n) => strtolower(basename($n)) === $basename);
            if ($matches !== []) {
                return $this->shallowest($matches);
            }
        }

        return $this->shallowest($html);
    }

    /**
     * Match `$regex` (one capture group = a folder name) against every candidate
     * and pick the one whose folder best matches the game name. Falls back to a
     * candidate that isn't an obvious shared demo template.
     *
     * @param  list<string>  $names
     * @param  non-empty-string  $regex
     */
    private function pickByFolder(array $names, string $regex, ?string $code): ?string
    {
        $candidates = [];
        foreach ($names as $name) {
            if (preg_match($regex, $name, $m) === 1) {
                $candidates[$name] = $this->normalise($m[2] ?? $m[1]);
            }
        }
        if ($candidates === []) {
            return null;
        }

        $want = $code !== null ? $this->normalise($this->stripSuffix($code)) : '';
        if ($want !== '') {
            foreach ($candidates as $path => $folder) {
                if ($folder === $want || str_contains($want, $folder) || str_contains($folder, $want)) {
                    return $path;
                }
            }
        }

        // No name match — drop known shared demo shells, then shallowest.
        $filtered = array_keys(array_filter(
            $candidates,
            fn (string $folder) => ! in_array($folder, ['40megaslot', '40megaslots', 'megaslot'], true),
        ));

        return $this->shallowest($filtered ?: array_keys($candidates));
    }

    private function normalise(string $s): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $s));
    }

    private function stripSuffix(string $code): string
    {
        $suffix = $this->suffixOf($code);

        return $suffix !== null ? substr($code, 0, -strlen($suffix)) : $code;
    }

    /**
     * @param  list<string>  $names
     * @param  non-empty-string  $regex
     */
    private function firstLike(array $names, string $regex): ?string
    {
        $matches = array_values(array_filter($names, fn (string $n) => preg_match($regex, $n) === 1));

        return $this->shallowest($matches);
    }

    /** @param iterable<string> $names Shallowest path wins; ties broken by shortest then lexical. */
    private function shallowest(iterable $names): ?string
    {
        $list = is_array($names) ? array_values($names) : iterator_to_array($names, false);
        if ($list === []) {
            return null;
        }

        usort($list, fn (string $a, string $b) => [substr_count($a, '/'), strlen($a), $a] <=> [substr_count($b, '/'), strlen($b), $b]);

        return $list[0];
    }

    /** The trailing provider token of a game code, or null. */
    public function suffixOf(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach (self::CODE_SUFFIXES as $suffix) {
            if (str_ends_with($code, $suffix) && strlen($code) > strlen($suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    /**
     * Human-readable game name derived from a code: strip the provider suffix
     * (and a trailing mobile "M"), then split the CamelCase / digit runs.
     * "AgeOfEgyptPT" → "Age Of Egypt", "Royal20FruitsNG" → "Royal 20 Fruits".
     */
    public function prettyName(string $code): string
    {
        $base = $this->stripSuffix($code);

        // A leftover mobile marker once the suffix is gone: a trailing capital M
        // after a letter or digit (AgeOfEgyptPTM → …PT → …, PandasFortune2M → …).
        if (strlen($base) > 4 && str_ends_with($base, 'M') && preg_match('/[a-z0-9]M$/', $base) === 1) {
            $base = substr($base, 0, -1);
        }

        $spaced = preg_replace(
            [
                '/([a-z])([A-Z])/',        // camelCase   → camel Case
                '/([A-Z]+)([A-Z][a-z])/',  // HTTPServer   → HTTP Server
                '/([a-z])([0-9])/',        // Royal20      → Royal 20
                '/([0-9])([A-Z])/',        // 20Fruits     → 20 Fruits (not "7s")
            ],
            '$1 $2',
            $base,
        ) ?? $base;

        return Str::of($spaced)->replace('_', ' ')->squish()->title()->toString();
    }
}
