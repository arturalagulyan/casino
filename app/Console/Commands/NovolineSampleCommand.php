<?php

namespace App\Console\Commands;

use App\Models\NovolineRound;
use App\Services\GamePlay\NetEnt\BetboySite;
use App\Services\GamePlay\Novoline\NovolineLibrary;
use App\Services\GamePlay\Novoline\NovolineLiveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Record whole rounds of a Novoline (Greentube) slot into its outcome library
 * (`novoline_rounds`), played on Betboy DEMO sessions (fun credits — never the
 * account's money) at the smallest line bet on every line. A session is spent
 * down before a fresh one is launched (Betboy limits launches). Resumable: it
 * only adds rounds.
 *
 *   php artisan novoline:sample bookofradeluxe_gt_html --rounds=20000
 */
class NovolineSampleCommand extends Command
{
    protected $signature = 'novoline:sample
        {game : Betboy game id or name (bookofradeluxe_gt_html, "Book of Ra Deluxe")}
        {--rounds=1000 : Rounds to record}
        {--pace=20 : Milliseconds between rounds}
        {--betboy-token= : Betboy AuthByToken token (default: BETBOY_TOKEN)}';

    protected $description = 'Record demo rounds of a Novoline slot into its outcome library';

    /** State fields the protocol knows (money in them is scaled, or they carry none). */
    public const array KNOWN = ['S', 'A', 'C', 'T', 'R', 'M', 'I', 'H', 'X', 'e', 'b', 's', 'h', 'r', 'l', 'c', 'o', 'm', 'f', 'W', ':x', 'q', 'g', 'x', ':U', ':d', ''];

    public function handle(BetboySite $betboy, NovolineLibrary $library): int
    {
        $betboy->withToken($this->option('betboy-token') ?: null);
        $game = $betboy->game((string) $this->argument('game'));
        $key = (string) $game['stringId'];
        $target = (int) $this->option('rounds');
        $client = null;
        $cost = 0;
        $commands = [];
        $idle = [];
        $factor = 100.0;
        $done = $errors = $sessions = $odd = 0;
        $unknown = [];

        while ($done < $target && $errors < 30) {
            try {
                if ($client === null || $client->balance() < max($cost, 1) * 2) {   // spend a session down, then a new one
                    $client?->close();
                    $client = self::connect($betboy, $key);
                    $client->commands = $commands + $client->commands;
                    $client->idle = $idle;
                    $factor = self::factor($client->login);
                    $sessions++;
                }
                usleep((int) $this->option('pace') * 1000);
                $round = $client->round();
                $commands = $client->commands;
                $idle = $client->idle;
                $cost = $round['bet'];
                if ($round['win'] < 0) {
                    $odd++;
                    $this->warn("  round skipped: stake {$round['bet']}, balance moved by ".($round['win'] - $round['bet']));

                    continue;
                }
                foreach ($round['steps'] as $s) {
                    foreach ($s['r'] as $f) {
                        if (($f[0] ?? '') === "\x06") {
                            foreach (NovolineLiveClient::fields($f) as $x) {
                                $k = NovolineLiveClient::key($x);
                                if (! in_array($k, self::KNOWN, true)) {
                                    $unknown[$k] ??= $x;
                                }
                            }
                        }
                    }
                }
                NovolineRound::create([
                    'game_key' => $key,
                    'bet_cents' => (int) round($round['bet'] * 100 / $factor), 'win_cents' => (int) round($round['win'] * 100 / $factor),
                    'feature' => $round['feature'], 'start_credit' => $round['start_credit'],
                    'steps' => $round['steps'], 'created_at' => now(),
                ]);
                if (++$done % 250 === 0) {
                    $this->line("  {$done} rounds recorded");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn('  '.mb_strimwidth($e->getMessage(), 0, 160, '…').' — new session');
                $commands = $client?->commands ?? $commands;
                $client?->close();
                $client = null;
                sleep(min(30, $errors));
            }
        }
        $client?->close();
        if ($unknown !== []) {
            $this->warn('  state fields the protocol does not scale: '.implode('  ', array_map(fn ($k, $x) => mb_strimwidth($x, 0, 40), array_keys($unknown), $unknown)));
        }
        $library->forget($key);
        $s = $library->stats($key);
        $this->info(sprintf('%d rounds recorded on %d demo session(s), %d error(s), %d skipped — library now %d rounds, return %.2f%%, %d with features',
            $done, $sessions, $errors, $odd, $s['rounds'], $s['rtp'], $s['features']));

        return $errors >= 30 ? self::FAILURE : self::SUCCESS;
    }

    /** A fresh demo session, logged in, at the smallest line bet. */
    public static function connect(BetboySite $betboy, string $key): NovolineLiveClient
    {
        $page = $betboy->demo($key)['page'];
        $client = NovolineLiveClient::fromPage(Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($page)->body(), $key);
        $client->open();
        [$lines, $perLine, $x] = self::bet($client->state);
        $min = self::lineBets($client->state)[0] ?? $perLine;
        if ($min !== $perLine) {
            $client->setBet($min, $lines, $x);
        }

        return $client;
    }

    /** @return array{0: int, 1: int, 2: string} lines, line bet, the bet's third value — off the init's `e` */
    public static function bet(string $state): array
    {
        $e = NovolineLiveClient::field($state, 'e') ?? ['1', '1', '0'];

        return [(int) $e[0], (int) ($e[1] ?? 1), (string) ($e[2] ?? '0')];
    }

    /** @return list<int> the line bets the game offers (`:b,<count>,…`) */
    public static function lineBets(string $state): array
    {
        $b = NovolineLiveClient::field($state, ':b') ?? [];

        return array_values(array_map('intval', array_slice($b, 1)));
    }

    /** @param list<string> $login credits a unit of money is worth (CURRENCYFACTOR) */
    public static function factor(array $login): float
    {
        foreach ($login as $f) {
            if (($f[0] ?? '') === "\x07") {
                $v = explode(NovolineLiveClient::SEP, substr($f, 1));
                $i = array_search('CURRENCYFACTOR', $v, true);
                if ($i !== false && (float) ($v[$i + 1] ?? 0) > 0) {
                    return (float) $v[$i + 1];
                }
            }
        }

        return 100.0;
    }
}
