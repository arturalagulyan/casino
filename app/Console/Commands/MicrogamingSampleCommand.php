<?php

namespace App\Console\Commands;

use App\Models\GameTemplate;
use App\Models\MicrogamingRound;
use App\Services\GamePlay\Microgaming\MicrogamingFamily;
use App\Services\GamePlay\Microgaming\MicrogamingLibrary;
use App\Services\GamePlay\Microgaming\MicrogamingLiveClient;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Record whole rounds of a Microgaming slot into its outcome library
 * (`microgaming_rounds`), played on Betboy DEMO sessions (fun credits — never
 * the account's money) at one coin of the smallest chip. A session running low
 * on fun credits is swapped for a fresh one. Resumable: it only adds rounds.
 *
 *   php artisan microgaming:sample thunderstruckii_mg_html --rounds=20000
 */
class MicrogamingSampleCommand extends Command
{
    protected $signature = 'microgaming:sample
        {game : Betboy game id or name (thunderstruckii_mg_html, "Thunderstruck II")}
        {--rounds=1000 : Rounds to record}
        {--pace=30 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Microgaming slot into its outcome library';

    public function handle(BetboySite $betboy, MicrogamingLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        // what the fetch stored: the packet version the client sends, extra Spin attributes (Dream Date: reelSetChoiceId)
        $cfg = GameTemplate::query()->where('client_protocol', 'microgaming')->get(['bonus_config'])
            ->first(fn (GameTemplate $t) => ($t->bonus_config['microgaming']['game_key'] ?? null) === $key)
            ?->bonus_config['microgaming'] ?? [];
        $version = $cfg['packet_version'] ?? null;
        $spinExtra = array_map('strval', (array) ($cfg['spin_extra'] ?? []));
        $init = '';
        $chip = $stake = 0;
        $done = $errors = $sessions = 0;

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->credit() < $stake * 2) {   // spend the session down (Betboy limits launches)
                    $client = MicrogamingLiveClient::fromLaunch($betboy->launchUrl($key));
                    if ($version !== null) {   // the version the fetch found the client sending
                        $client->packetVersion = $version;
                    }
                    $client->featureChoices = (int) ($cfg['feature_choices'] ?? 0);
                    $init = $client->open();   // refuses anything but a demo session
                    $chip = MicrogamingFamily::chips($init)[0] ?? 1;
                    $stake = MicrogamingFamily::stake($init, $chip, 1);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round($spinExtra + MicrogamingFamily::spin($init, $chip));
                MicrogamingRound::create([
                    'game_key' => $key, 'bet_cents' => $stake,
                    // what the round paid: the balance it ended on, less the balance before the stake
                    'win_cents' => max(0, $client->credit() - $round['start_credit'] + $stake),
                    'feature' => count($round['steps']) > 1, 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
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
