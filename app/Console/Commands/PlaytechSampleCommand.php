<?php

namespace App\Console\Commands;

use App\Models\PlaytechRound;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\Playtech\PlaytechGames;
use App\Services\GamePlay\Playtech\PlaytechLibrary;
use App\Services\GamePlay\Playtech\PlaytechLiveClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a Playtech slot into its outcome library
 * (`playtech_rounds`), played on Betboy DEMO sessions (fun credits — never
 * the account's money) at coin 1. A session running low on fun credits is
 * swapped for a fresh one. Resumable: it only adds rounds.
 *
 *   php artisan playtech:sample whk_pt --rounds=20000
 */
class PlaytechSampleCommand extends Command
{
    protected $signature = 'playtech:sample
        {game : Betboy game id or name (whk_pt, "White King")}
        {--rounds=1000 : Rounds to record}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Playtech slot into its outcome library';

    public function handle(BetboySite $betboy, PlaytechLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $spec = PlaytechGames::spec($key);
        $bet = (int) $spec['bet'];
        $target = (int) $this->option('rounds');
        $client = null;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit < $bet * 50) {   // spend the session down (Betboy limits launches)
                    $client?->close();
                    $client = PlaytechLiveClient::fromUrl($betboy->demo($key)['page']);
                    $client->open();   // refuses anything but a demo session
                    $sessions++;
                }
                $round = $client->round($spec);
                PlaytechRound::create([
                    'game_key' => $key, 'bet_cents' => $bet,
                    // what the round paid: the credit before the next round's stake, less the credit before this one's
                    'win_cents' => max(0, $round['end_credit'] - $round['start_credit'] + $bet),
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 300, '…').' — new session');
                $client?->close();
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $client?->close();
        $library->annotateStages($key);   // games with stages: which stage each round is on, and where the recording moved next
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with free spins / respins',
            $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
