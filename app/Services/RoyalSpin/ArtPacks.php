<?php

namespace App\Services\RoyalSpin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Stock artwork the Game Builder draws from. Two sources, one shape:
 *
 *   resources/games/royalspin/packs/<key>/pack.json   dedicated packs (e.g. "olympus")
 *   resources/games/royalspin/games/<Code>/            every shipped RoyalSpin game's
 *                                                      own art doubles as a pack
 *
 * A pack = symbols (key, name, kind: low|high|wild|scatter|multiplier, file),
 * backgrounds, logo, optional character + multiplier orbs, music and a default
 * theme. Paths inside a pack are relative to its directory.
 */
class ArtPacks
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $packs = null;

    public static function root(): string
    {
        return resource_path('games/royalspin');
    }

    /** @return array<string, array<string, mixed>> key => pack */
    public function all(): array
    {
        if ($this->packs !== null) {
            return $this->packs;
        }

        $packs = [];
        foreach (File::glob(self::root().'/packs/*/pack.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
            $dir = dirname($file);
            $key = (string) ($data['key'] ?? basename($dir));
            $packs[$key] = $this->normalise($key, $dir, $data);
        }
        foreach (File::directories(self::root().'/games') as $dir) {
            if ($pack = $this->fromGame($dir)) {
                $packs[$pack['key']] = $pack;
            }
        }

        return $this->packs = $packs;
    }

    /** @return array<string, mixed>|null */
    public function get(?string $key): ?array
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** @return array<string, string> key => title, for selects */
    public function options(): array
    {
        return array_map(fn (array $p) => $p['title'], $this->all());
    }

    /** @return array<string, string> symbol key => name */
    public function symbolOptions(?string $pack): array
    {
        return array_map(fn (array $s) => $s['name'], $this->get($pack)['symbols'] ?? []);
    }

    /** Absolute path of a file inside a pack, or null when it doesn't exist / escapes the pack. */
    public function path(string $pack, ?string $rel): ?string
    {
        $p = $this->get($pack);
        if (! $p || ! $rel || str_contains($rel, '..')) {
            return null;
        }
        $abs = $p['dir'].'/'.ltrim($rel, '/');

        return is_file($abs) ? $abs : null;
    }

    /** Absolute path of a pack symbol's art. */
    public function symbolPath(string $pack, ?string $symbol): ?string
    {
        $file = $this->get($pack)['symbols'][$symbol ?? '']['file'] ?? null;

        return $file ? $this->path($pack, $file) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(string $key, string $dir, array $data): array
    {
        $symbols = [];
        foreach ((array) ($data['symbols'] ?? []) as $s) {
            $symbols[(string) $s['key']] = [
                'key' => (string) $s['key'],
                'name' => (string) ($s['name'] ?? $s['key']),
                'kind' => (string) ($s['kind'] ?? 'low'),
                'file' => (string) $s['file'],
            ];
        }

        return [
            'key' => $key,
            'title' => (string) ($data['title'] ?? Str::headline($key)),
            'skin' => (string) ($data['skin'] ?? 'classic'),
            'dir' => str_replace('\\', '/', $dir),
            'symbols' => $symbols,
            'orbs' => array_values((array) ($data['orbs'] ?? [])),
            'background' => $data['background'] ?? null,
            'background_free' => $data['background_free'] ?? null,
            'character' => $data['character'] ?? null,
            'logo' => $data['logo'] ?? null,
            'music' => $data['music'] ?? null,
            'music_free' => $data['music_free'] ?? null,
            'theme' => (array) ($data['theme'] ?? []),
        ];
    }

    /**
     * A shipped game's art as a pack: symbols are its img/sym/<id>.svg, named from game.json.
     *
     * @return array<string, mixed>|null
     */
    private function fromGame(string $dir): ?array
    {
        if (! is_file($dir.'/game.json') || ! is_dir($dir.'/img/sym')) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($dir.'/game.json'), true) ?: [];
        $math = is_file($dir.'/math.json') ? (json_decode((string) file_get_contents($dir.'/math.json'), true) ?: []) : [];
        $code = basename($dir);

        $ids = [];
        foreach (File::glob($dir.'/img/sym/*.svg') ?: [] as $f) {
            $ids[] = (int) pathinfo($f, PATHINFO_FILENAME);
        }
        sort($ids);
        $special = [];
        foreach (['wild' => $math['wild_symbol'] ?? null, 'scatter' => $math['scatter_symbol'] ?? null, 'multiplier' => $math['tumble_config']['multiplier_symbol'] ?? null] as $kind => $id) {
            if ($id !== null) {
                $special[(int) $id] = $kind;
            }
        }
        $plain = array_values(array_filter($ids, fn ($id) => ! isset($special[$id])));
        $symbols = [];
        foreach ($ids as $id) {
            $rank = array_search($id, $plain, true);
            $kind = $special[$id] ?? ($rank !== false && $rank >= count($plain) * 0.6 ? 'high' : 'low');
            $symbols[(string) $id] = [
                'key' => (string) $id,
                'name' => (string) ($meta['symbols'][(string) $id] ?? 'Symbol '.$id),
                'kind' => $kind,
                'file' => "img/sym/{$id}.svg",
            ];
        }

        $theme = (array) ($meta['theme'] ?? []);

        return [
            'key' => Str::kebab((string) preg_replace('/RS$/', '', $code)),
            'title' => ($meta['title'] ?? $code).' (art of the shipped game)',
            'skin' => 'classic',
            'dir' => str_replace('\\', '/', $dir),
            'symbols' => $symbols,
            'orbs' => [],
            'background' => 'img/bg.svg',
            'background_free' => is_file($dir.'/img/bg_free.svg') ? 'img/bg_free.svg' : null,
            'character' => null,
            'logo' => 'img/logo.svg',
            'music' => is_file($dir.'/snd/music.wav') ? 'snd/music.wav' : null,
            'music_free' => is_file($dir.'/snd/music_free.wav') ? 'snd/music_free.wav' : null,
            'theme' => [
                'accent' => $theme['accent'] ?? '#ffd84a',
                'frame' => $theme['frame'] ?? ['#fff2a8', '#d4a017', '#7a5200'],
                'reelBg' => $theme['reelBg'] ?? ['#14141c', '#06060a'],
                'lineColors' => $theme['lineColors'] ?? null,
            ],
        ];
    }
}
