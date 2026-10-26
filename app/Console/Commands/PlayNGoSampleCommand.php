<?php

namespace App\Console\Commands;

use App\Models\PlayNGoRound;
use App\Services\GamePlay\PlayNGo\PlayNGoLiveClient;
use App\Services\GamePlay\PlayNGo\PlayNGoSite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole practice rounds of a Play'n GO game from Play'n GO's own server
 * into the outcome library (`playngo_rounds`). Resumable: it only adds rounds.
 *
 *   php artisan playngo:sample tomeofmadness --rounds=20000
 *   php artisan playngo:sample tomeofmadness --from=/tmp/rounds.jsonl   # load recorded rounds
 */
class PlayNGoSampleCommand extends Command
{
    protected $signature = 'playngo:sample
        {gid : Play\'n GO game id, e.g. tomeofmadness}
        {--rounds=1000 : Rounds to record}
        {--coin=10 : Coin value (cents) to play at — wins are recorded in coins, so any works}
        {--pace=150 : Milliseconds between rounds}
        {--from= : Load rounds from a JSON-lines file instead (one [{sent, reply}, …] per line)}';

    protected $description = "Record practice rounds of a Play'n GO game into its outcome library";

    /** The client's spin: always 10 coins, one "line", at the chosen coin value. */
    private const int BET_COINS = 10;

    public function handle(PlayNGoSite $site): int
    {
        $gid = strtolower((string) $this->argument('gid'));

        if ($file = $this->option('from')) {
            $n = 0;
            foreach (file((string) $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $steps = json_decode($line, true);
                if (is_array($steps) && $steps !== []) {
                    $this->store($gid, $steps);
                    $n++;
                }
            }
            $this->info("{$n} rounds loaded — library now ".$this->count($gid).' rounds');

            return self::SUCCESS;
        }

        $game = $site->game($gid);
        $settings = $site->settingsXml($site->configuration($game['id']));
        $spin = sprintf('1 %d 1 %d 1', self::BET_COINS, (int) $this->option('coin'));
        $target = (int) $this->option('rounds');
        $client = null;
        $done = $errors = 0;

        while ($done < $target && $errors < 50) {
            try {
                if ($client === null) {
                    $client = new PlayNGoLiveClient;
                    $client->open($game['id'], $game['name'], $settings);
                }
                usleep((int) $this->option('pace') * 1000);
                $this->store($gid, $client->round($spin));
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — reconnecting');
                $client?->close();
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $client?->close();
        $this->info("{$done} rounds recorded ({$errors} reconnects) — library now ".$this->count($gid).' rounds');

        return self::SUCCESS;
    }

    private function store(string $gid, array $steps): void
    {
        PlayNGoRound::create([
            'game_key' => $gid, 'bet_coins' => self::BET_COINS, 'win_coins' => PlayNGoRound::winOf($steps),
            'feature' => PlayNGoRound::hasFeature($steps), 'steps' => $steps, 'created_at' => now(),
        ]);
    }

    private function count(string $gid): int
    {
        return PlayNGoRound::query()->where('game_key', $gid)->count();
    }
}
