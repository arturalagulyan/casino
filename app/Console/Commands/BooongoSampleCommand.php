<?php

namespace App\Console\Commands;

use App\Models\BooongoRound;
use App\Services\GamePlay\Booongo\BooongoLibrary;
use App\Services\GamePlay\Booongo\BooongoLiveClient;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a Booongo slot into its outcome library
 * (`booongo_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at the game's smallest line bet on every line. A session
 * running low on fun credits is swapped for a fresh one. Resumable: it only
 * adds rounds.
 *
 *   php artisan booongo:sample book_of_sun_bng_html --rounds=20000
 */
class BooongoSampleCommand extends Command
{
    protected $signature = 'booongo:sample
        {game : Betboy game id or name (book_of_sun_bng_html, "Book of Sun")}
        {--rounds=1000 : Rounds to record}
        {--pace=0 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Booongo slot into its outcome library';

    public function handle(BetboySite $betboy, BooongoLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        $stake = 0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit() < $stake * 2) {   // a spin never costs more than its stake: spend the session down (Betboy limits launches)
                    $client = BooongoLiveClient::fromPage($betboy->demo($key)['page']);
                    $client->open();   // refuses anything but fun credits
                    $stake = (int) round($client->minBet() * max((array) ($client->start['settings']['bet_factor'] ?? [1]) ?: [1]));
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($client->minBet(), $client->lines());
                $bet = (int) ($round['steps'][0]['context']['spins']['round_bet'] ?? $stake);
                $last = end($round['steps']);
                BooongoRound::create([
                    'game_key' => $key, 'bet_cents' => $bet,
                    // what the round paid: the credit it ended on, less the credit before the stake
                    'win_cents' => max(0, (int) $last['user']['balance'] - $round['start_credit'] + $bet),
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
                    'steps' => array_map(fn (array $s) => ['context' => $s['context'], 'balance' => (int) $s['user']['balance']], $round['steps']),
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
