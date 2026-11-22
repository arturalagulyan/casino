<?php

namespace App\Console\Commands;

use App\Models\EgtRound;
use App\Services\GamePlay\Egt\EgtLibrary;
use App\Services\GamePlay\Egt\EgtLiveClient;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of an EGT slot into its outcome library (`egt_rounds`),
 * played on Betboy DEMO sessions (fun credits — never the account's money) at
 * the game's smallest bet. A session running low on fun credits is swapped
 * for a fresh one. Resumable: it only adds rounds.
 *
 *   php artisan egt:sample burning_hot_html --rounds=20000
 */
class EgtSampleCommand extends Command
{
    protected $signature = 'egt:sample
        {game : Betboy game id or name (burning_hot_html, "Burning Hot")}
        {--rounds=1000 : Rounds to record}
        {--pace=20 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of an EGT slot into its outcome library';

    public function handle(BetboySite $betboy, EgtLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $target = (int) $this->option('rounds');
        $client = null;
        $gin = 0;
        $bet = 0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->balance < $bet * 2) {   // a round never costs more than its bet: spend the session down (Betboy limits launches)
                    $client?->close();
                    $client = EgtLiveClient::fromPage($betboy->demo((string) $game['stringId'])['page']);
                    $client->open();   // refuses anything but a demo session
                    $gin = $client->gin();
                    $bet = (int) min((array) $client->settings['complex']['bets']);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($bet);
                EgtRound::create([
                    'gin' => $gin, 'bet_cents' => $bet,
                    // what the round paid: the balance it ended on (win collected), less the balance before the bet
                    'win_cents' => max(0, $round['end_balance'] - $round['start_balance'] + $bet),
                    'feature' => count($round['steps']) > 1, 'start_balance' => $round['start_balance'],
                    'steps' => array_map([self::class, 'strip'], $round['steps']), 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 200, '…').' — new session');
                $client?->close();
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $client?->close();
        if ($gin > 0) {
            $library->forget($gin);
            $s = $library->stats($gin);
            $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with a feature',
                $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));
        }

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A reply without its envelope (session, message id, timestamps): what a
     * served step is rebuilt from.
     *
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    public static function strip(array $reply): array
    {
        return array_diff_key($reply, array_flip(['sessionKey', 'msg', 'messageId', 'qName', 'command', 'eventTimestamp',
            'gameIdentificationNumber', 'gameNumber']));
    }
}
