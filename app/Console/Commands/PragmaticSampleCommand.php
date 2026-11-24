<?php

namespace App\Console\Commands;

use App\Models\PragmaticRound;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\PragmaticPlay\PragmaticLibrary;
use App\Services\GamePlay\PragmaticPlay\PragmaticLiveClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a Pragmatic Play slot into its outcome library
 * (`pragmatic_rounds`), played on Betboy DEMO sessions (fun credits — never
 * the account's money) at the game's smallest coin on its lines. A session
 * running low on fun credits is swapped for a fresh one. Resumable: it only
 * adds rounds.
 *
 *   php artisan pragmatic:sample vs20fruitsw_prg --rounds=20000
 */
class PragmaticSampleCommand extends Command
{
    protected $signature = 'pragmatic:sample
        {game : Betboy game id or name (vs20fruitsw_prg, "Sweet Bonanza")}
        {--rounds=1000 : Rounds to record}
        {--coin= : Coin to record at (default: the template\'s record coin, else 0.01)}
        {--pace=0 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Pragmatic Play slot into its outcome library';

    public function handle(BetboySite $betboy, PragmaticLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $coin = (float) ($this->option('coin') ?: 0.01);
        $client = null;
        $lines = 1;
        $stake = 0.0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit() < $stake * 3) {   // spend the session down (Betboy limits launches)
                    $client = PragmaticLiveClient::fromPage($betboy->demo($key)['launch']);
                    $init = $client->open();   // refuses anything but fun credits
                    $lines = (int) ($init['l'] ?? 1);
                    $stake = $coin * $lines;
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($coin, $lines);
                $start = (int) round($round['start_credit'] * 100);
                $bet = $start - (int) round((float) $round['steps'][0]['reply']['balance'] * 100);
                if ($bet !== (int) round($stake * 100)) {
                    throw new \RuntimeException("the spin took {$bet} cents, not coin × lines = ".round($stake * 100));
                }
                // a feature: free spins, or a step that is no spin / collect (a bonus, a pick, an option)
                $feature = false;
                foreach ($round['steps'] as $s) {
                    $feature = $feature || isset($s['reply']['fs']) || ! in_array($s['action'], ['doSpin', 'doCollect'], true);
                }
                PragmaticRound::create([
                    'game_key' => $key, 'bet_cents' => $bet,
                    // what the round paid: the credit it ended on, less the credit before the stake
                    'win_cents' => max(0, (int) round($client->credit() * 100) - $start + $bet),
                    'feature' => $feature, 'start_credit' => $start,
                    'steps' => array_map(fn (array $s) => ['a' => $s['action'], 'p' => $s['params'], 'r' => PragmaticLiveClient::encode($s['reply'])], $round['steps']),
                    'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — new session');
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with features',
            $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
