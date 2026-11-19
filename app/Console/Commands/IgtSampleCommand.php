<?php

namespace App\Console\Commands;

use App\Models\IgtRound;
use App\Services\GamePlay\Igt\IgtLibrary;
use App\Services\GamePlay\Igt\IgtLiveClient;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of an IGT slot into its outcome library (`igt_rounds`),
 * played on Betboy DEMO sessions (fun credits — never the account's money)
 * at the smallest line bet on every line. A session is spent down before a
 * fresh one is launched (Betboy limits launches). Resumable: it only adds rounds.
 *
 *   php artisan igt:sample wolfrun_igt_html --rounds=20000
 */
class IgtSampleCommand extends Command
{
    protected $signature = 'igt:sample
        {game : Betboy game id or name (wolfrun_igt_html, "Wolf Run")}
        {--rounds=1000 : Rounds to record}
        {--pace=30 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of an IGT slot into its outcome library';

    public function handle(BetboySite $betboy, IgtLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        $cost = 0.0;   // a round's stake, money
        $step = 1.0;   // the line bet recorded at
        $done = $errors = $sessions = $odd = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->balance() < max($cost, 0.01) * 2) {   // a round never costs more than its stake
                    $picks = $client?->pickNames ?? $picks ?? [];   // what a session learnt, the next one keeps
                    $client = IgtLiveClient::fromPage($betboy->demo($key)['page']);
                    $client->pickNames = $picks;
                    $client->open();
                    $step = $client->minStep();
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($step);
                $cost = $round['bet'] / 100;
                // what the round paid is what the balance moved by, plus the stake —
                // the last reply's Payout can be part of it (Sphinx's pickers pay
                // in steps of their own)
                $win = (int) round($client->balance() * 100) - $round['start_credit'] + $round['bet'];
                if ($round['bet'] < 1 || $win < 0) {
                    $odd++;
                    $this->warn("  round skipped: stake {$round['bet']}, payout {$round['win']}, balance moved by ".($win - $round['bet']));

                    continue;
                }
                IgtRound::create([
                    'game_key' => $key, 'bet_cents' => $round['bet'], 'win_cents' => $win,
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — new session');
                $picks = $client?->pickNames ?? [];
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s), %d skipped — library now %d rounds, return %.2f%%, %d with features',
            $done, $sessions, $errors, $odd, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
