<?php

namespace App\Console\Commands;

use App\Models\MerkurRound;
use App\Services\GamePlay\Merkur\MerkurLibrary;
use App\Services\GamePlay\Merkur\MerkurLiveClient;
use App\Services\GamePlay\Merkur\MerkurMirror;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a Merkur (Edict) slot into its outcome library
 * (`merkur_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at the game's smallest total stake on every line of its
 * largest line set. A session running low on fun credits is swapped for a fresh one.
 * Resumable: it only adds rounds.
 *
 *   php artisan merkur:sample multifruits_mk_html --rounds=20000
 */
class MerkurSampleCommand extends Command
{
    protected $signature = 'merkur:sample
        {game : Betboy game id or name (multifruits_mk_html, "Multi Fruits")}
        {--rounds=1000 : Rounds to record}
        {--pace=30 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Merkur slot into its outcome library';

    public function handle(BetboySite $betboy, MerkurLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        $play = null;
        $split = MerkurMirror::splitCoins($key);   // how Merkur's own client counts coins (null: decided by the INIT)
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                // a round never costs more than its stake: spend the session down (Betboy limits launches)
                if ($client === null || $play === null || $client->depot() < $play['stake'] * 2) {
                    $client?->close();
                    $client = MerkurLiveClient::fromPage($betboy->demo($key)['page']);
                    $init = $client->open()['init'];   // refuses anything but a fun-credit session
                    $play = MerkurLiveClient::recording($init, MerkurLiveClient::splitCoins($init, $split));
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($play['wager'], $play['positions'], $play['coin']);
                $last = end($round['steps']);
                $bet = $play['stake'];
                MerkurRound::create([
                    'game_key' => $key, 'bet_cents' => $bet,
                    // what the round paid: the depot it ended on, less the depot before the stake
                    'win_cents' => max(0, (int) $last['core']['depot']['balance'] - $round['start_depot'] + $bet),
                    'feature' => count(array_filter($round['steps'], fn ($s) => $s['action'] === 'PLAY')) > 1,
                    'start_credit' => $round['start_depot'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — new session');
                $client?->close();
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $client?->close();
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with features',
            $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
