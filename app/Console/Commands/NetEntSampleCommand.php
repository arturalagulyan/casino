<?php

namespace App\Console\Commands;

use App\Models\NetEntRound;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\NetEnt\IncompleteRound;
use App\Services\GamePlay\NetEnt\NetEntLibrary;
use App\Services\GamePlay\NetEnt\NetEntLiveClient;
use App\Services\GamePlay\NetEnt\NetEntQuirks;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a NetEnt game into its outcome library
 * (`netent_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at bet level 1, coin value 1 cent, every line / way the init offers. A session
 * running low on fun credits is swapped for a fresh one. Resumable: it only
 * adds rounds.
 *
 *   php artisan netent:sample happy_riches --rounds=20000
 */
class NetEntSampleCommand extends Command
{
    protected $signature = 'netent:sample
        {game : Betboy game id or name (happy_riches, "Happy Riches")}
        {--rounds=1000 : Rounds to record}
        {--pace=50 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a NetEnt game into its outcome library';

    public function handle(BetboySite $betboy, NetEntLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $target = (int) $this->option('rounds');
        $client = null;
        $betLines = '';
        $denom = 1;            // the coin value recorded at, cents
        $stake = 0;            // the base cost, coins: measured on the first plain round
        $done = $errors = $sessions = $dropped = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit() < max($stake, 1) * 50) {
                    $client = new NetEntLiveClient($betboy->demo((string) $game['stringId'])['page']);
                    $init = $client->init();
                    if (($init['playercurrencyiso'] ?? '') !== 'NAN' && ($init['playforfun'] ?? '') !== 'true') {
                        $this->error("Betboy opened a session in {$init['playercurrencyiso']}, not a demo — refusing to stake real money.");

                        return self::FAILURE;
                    }
                    $betLines = NetEntLiveClient::betLines($init);
                    $denom = NetEntQuirks::recordDenomination($init);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($betLines, 1, $denom);
                if ($round['stake'] !== null) {
                    $round['stake'] = intdiv($round['stake'], $denom);   // cents → coins
                }
                // the base cost comes from a one-step round whose reply names the
                // credit; a round that ends without one (some feature endings) costs
                // the same, so it is recorded at that cost — or dropped, before any
                if ($stake === 0 && $round['stake'] !== null && NetEntQuirks::isPlain($client->gameId(), $round['steps'])) {
                    if ($round['stake'] < 1) {
                        throw new \RuntimeException("a round cost {$round['stake']} coins — betlines '{$betLines}' not understood");
                    }
                    $stake = $round['stake'];
                }
                if ($stake === 0) {
                    continue;
                }
                if ($round['stake'] !== null && $round['stake'] !== $stake) {
                    $this->warn("  round cost {$round['stake']} coins, not {$stake} — recorded at {$stake}");
                }
                NetEntRound::create([
                    'game_key' => $client->gameId(), 'bet_coins' => $stake, 'win_coins' => NetEntRound::winOf($round['steps']),
                    'feature' => ! NetEntQuirks::isPlain($client->gameId(), $round['steps']), 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (IncompleteRound $e) {
                // a pick bonus the recording cannot play: drop the round, and the
                // session, which the server now holds inside that bonus
                $dropped++;
                $client = null;
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — new session');
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $key = $client?->gameId() ?? (string) $game['stringId'];
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s), %d unplayable bonus round(s) dropped — library now %d rounds, return %.2f%%, %d with features',
            $done, $sessions, $errors, $dropped, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
