<?php

namespace App\Console\Commands;

use App\Models\WazdanRound;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\Wazdan\WazdanLibrary;
use App\Services\GamePlay\Wazdan\WazdanLiveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Record whole rounds of a Wazdan slot into its outcome library
 * (`wazdan_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at the game's smallest stake. A session running low on fun
 * credits is swapped for a fresh one. Resumable: it only adds rounds.
 *
 *   php artisan wazdan:sample sh_wzdn --rounds=20000
 */
class WazdanSampleCommand extends Command
{
    protected $signature = 'wazdan:sample
        {game : Betboy game id or name (sh_wzdn, "Super Hot")}
        {--rounds=1000 : Rounds to record}
        {--pace=20 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Wazdan slot into its outcome library';

    public function handle(BetboySite $betboy, WazdanLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        $arg = [];
        $stake = 0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit < $stake * 2) {   // spend the session down (Betboy limits launches)
                    $client?->close();
                    $page = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($betboy->demo($key)['page'])->throw()->body();
                    $client = WazdanLiveClient::fromPage($page);
                    $init = $client->open();   // refuses anything but a demo session
                    $stakes = array_values(array_filter(array_map('intval', explode(',', (string) ($init['GAME']['stake_list'] ?? '')))));
                    $stake = min($stakes);
                    $arg = FetchWazdanGameCommand::startArg($init, $stake);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($arg);
                WazdanRound::create([
                    'game_key' => $key, 'bet_cents' => $stake,
                    // what the round paid: the credit it ended on (win banked), less the credit before the stake
                    'win_cents' => max(0, $round['end_credit'] - $round['start_credit'] + $stake),
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                    $client->ping();
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
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s) — library now %d rounds, return %.2f%%, %d with free games / respins',
            $done, $sessions, $errors, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }
}
