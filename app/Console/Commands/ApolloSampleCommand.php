<?php

namespace App\Console\Commands;

use App\Models\ApolloRound;
use App\Services\GamePlay\Apollo\ApolloLibrary;
use App\Services\GamePlay\Apollo\ApolloLiveClient;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of an Apollo Games slot into its outcome library
 * (`apollo_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at the game's smallest stake. A session running low on
 * fun credits is swapped for a fresh one. Resumable: it only adds rounds.
 *
 *   php artisan apollo:sample lucky81_ag --rounds=20000
 */
class ApolloSampleCommand extends Command
{
    protected $signature = 'apollo:sample
        {game : Betboy game id or name (lucky81_ag, "Lucky 81")}
        {--rounds=1000 : Rounds to record}
        {--pace=30 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of an Apollo Games slot into its outcome library';

    public function handle(BetboySite $betboy, ApolloLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $target = (int) $this->option('rounds');
        $client = null;
        $key = '';
        $stake = 0.0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit() < $stake * 2) {   // a round never costs more than its stake: spend the session down (Betboy limits launches)
                    $client?->close();
                    $client = ApolloLiveClient::fromPage($betboy->demo((string) $game['stringId'])['page']);
                    $init = $client->open();   // refuses anything but a demo session
                    $key = (string) $init['name'];
                    $stake = (float) min((array) $init['stakeOptions']);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($stake);
                $last = end($round['steps']);
                $bet = (int) round($stake * 100);
                ApolloRound::create([
                    'game_key' => $key, 'bet_cents' => $bet,
                    // what the round paid: the credit it ended on, less the credit before the stake
                    'win_cents' => max(0, (int) round((float) $last['credit'] * 100) - $round['start_credit'] + $bet),
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
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
        $key = $key ?: (string) $game['stringId'];
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with free spins',
            $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
