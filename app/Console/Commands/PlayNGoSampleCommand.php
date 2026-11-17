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
        {--spin= : The spin line, "1 <coins> <lines> <coin value> 1" (default: the game\'s default coins × lines)}
        {--pace=1100 : Least milliseconds between two packets — Play\'n GO cuts sessions off past about one a second}
        {--from= : Load rounds from a JSON-lines file instead (one [{sent, reply}, …] per line)}';

    protected $description = "Record practice rounds of a Play'n GO game into its outcome library";

    /** Coins a recorded spin stakes (coins × lines). */
    private int $betCoins = 10;

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
        $configuration = $site->configuration($game['id']);
        $settings = $site->settingsXml($configuration);
        [$coins, $lines] = FetchPlayNGoGameCommand::spinOf($configuration, $this->option('spin') ?: null);
        $this->betCoins = $coins * $lines;
        $spin = sprintf('1 %d %d %d 1', $coins, $lines, (int) $this->option('coin'));
        $this->line("  spin: {$spin}");
        $target = (int) $this->option('rounds');
        $client = null;
        $done = $errors = 0;

        while ($done < $target && $errors < 50) {
            try {
                if ($client === null) {
                    $client = new PlayNGoLiveClient;
                    $client->minGapMs = (int) $this->option('pace');
                    $client->open($game['id'], $game['name'], $settings);
                }
                $this->store($gid, $client->round($spin));
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $limited = str_contains($e->getMessage(), 'Rate limit') || str_contains($e->getMessage(), 'closed the hub');
                $errors += $limited ? 0 : 1;   // the rate limit is no fault: wait it out
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — reconnecting');
                $client?->close();
                $client = null;
                sleep($limited ? 90 : min(30, $errors));
            }
        }
        $client?->close();
        $this->info("{$done} rounds recorded ({$errors} reconnects) — library now ".$this->count($gid).' rounds');

        return self::SUCCESS;
    }

    private function store(string $gid, array $steps): void
    {
        PlayNGoRound::create([
            'game_key' => $gid, 'bet_coins' => $this->betCoins, 'win_coins' => PlayNGoRound::winOf($steps),
            'feature' => PlayNGoRound::hasFeature($steps), 'steps' => $steps, 'created_at' => now(),
        ]);
    }

    private function count(string $gid): int
    {
        return PlayNGoRound::query()->where('game_key', $gid)->count();
    }
}
