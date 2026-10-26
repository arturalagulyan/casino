<?php

namespace App\Services\GamePlay\PlayNGo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Play'n GO's public launch endpoints (the ones its ContainerLauncher page
 * calls): a game's numeric id/name by gid, and its configuration — resource
 * root (CDN build), modules, denominations.
 */
class PlayNGoSite
{
    public const string ORIGIN = 'https://released.playngonetwork.com/';

    /** @return array{gid: string, id: int, name: string, type: string} */
    public function game(string $gid): array
    {
        $g = Http::timeout(30)->acceptJson()->get(self::ORIGIN."games/getgamebygid/{$gid}")->json();
        if (! is_array($g) || ! isset($g['gameId'])) {
            throw new RuntimeException("Play'n GO doesn't know game '{$gid}'");
        }

        return ['gid' => (string) $g['gId'], 'id' => (int) $g['gameId'], 'name' => (string) $g['name'], 'type' => (string) ($g['gameType'] ?? '')];
    }

    /** The practice configuration the game client reads (configuration/v2). */
    public function configuration(int $gameId, string $channel = 'desktop', int $pid = 2): array
    {
        $c = Http::timeout(30)->acceptJson()->get(self::ORIGIN.'configuration/v2', [
            'pid' => $pid, 'gameid' => $gameId, 'lang' => 'en_GB', 'practice' => 1, 'brand' => '', 'ctx' => '',
            'channel' => $channel, 'jurisdiction' => 'ZZ', 'platform' => 'megaton', 'currency' => 'DEMO',
            'country' => '', 'region' => '', 'resourceRoot' => '',
        ])->json();
        if (! is_array($c) || ! isset($c['resourceRoot'])) {
            throw new RuntimeException("Play'n GO sent no configuration for game {$gameId}");
        }

        return $c;
    }

    /** The prelaunch configuration (game server URL, localised error texts). */
    public function prelaunch(int $gameId, string $channel = 'desktop'): array
    {
        return (array) Http::timeout(30)->acceptJson()->get(self::ORIGIN.'configuration/prelaunchconfiguration', [
            'gameId' => $gameId, 'language' => 'en_GB', 'productGroup' => 2, 'gapTime' => '', 'brand' => '', 'ctx' => '',
            'practice' => 1, 'channel' => $channel,
        ])->json();
    }

    /** The denominations XML the client sends with its game init (104), from the configuration's `settings`. */
    public function settingsXml(array $configuration): string
    {
        parse_str(ltrim((string) ($configuration['settings'] ?? ''), '&'), $q);

        return (string) ($q['settings'] ?? '<root><settings></settings></root>');
    }
}
