<?php

namespace App\Console\Commands;

use App\Services\GamePlay\SocketServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * The one game WebSocket server — a dumb bridge for every socket-based bundle,
 * like the legacy Slots.js.
 *
 * Socket bundles open a WebSocket and exchange `:::`-prefixed JSON frames; each
 * frame goes to {@see SocketServer}, which resolves the game and hands off to its
 * wire-protocol handler. First-class long-running service — run it under a
 * supervisor (the `gamesocket` compose service: `restart: unless-stopped`).
 *
 *   php artisan game:socket                 # foreground (dev / supervised)
 *   php artisan game:socket --stop          # stop a daemonised instance
 *   php artisan game:socket --status
 *   php artisan game:socket --workers=4     # scale the worker pool
 */
class GameSocketCommand extends Command
{
    protected $signature = 'game:socket
        {--daemon : Detach and run in the background}
        {--stop : Stop a running instance}
        {--restart : Restart a running instance}
        {--status : Show status}
        {--reload : Gracefully reload workers (picks up new code)}
        {--workers= : Number of worker processes (default: config games.socket.workers)}';

    protected $description = 'Run the one WebSocket server for all socket-based game front-ends';

    public function handle(SocketServer $server): int
    {
        $host = (string) config('games.socket.host', '0.0.0.0');
        $port = (int) config('games.socket.port', 2087);
        $workers = (int) ($this->option('workers') ?: config('games.socket.workers', 1));

        Worker::$pidFile = storage_path('framework/game-socket.pid');
        Worker::$logFile = storage_path('logs/game-socket.workerman.log');
        Worker::$stdoutFile = $this->option('daemon') ? storage_path('logs/game-socket.log') : '/dev/stdout';

        $worker = new Worker("websocket://{$host}:{$port}");
        $worker->name = 'game-socket';
        $worker->count = max(1, $workers);
        $worker->reloadable = true;

        $worker->onWorkerStart = function (Worker $w) {
            $this->log("worker {$w->id} up");
            // Keep the pooled DB connection from going stale on an idle socket.
            Timer::add(30, function () {
                try {
                    DB::connection()->getPdo()->query('SELECT 1');
                } catch (Throwable) {
                    try {
                        DB::reconnect();
                    } catch (Throwable) {
                    }
                }
            });
        };

        $worker->onWorkerStop = fn (Worker $w) => $this->log("worker {$w->id} stopping");

        $worker->onMessage = function (TcpConnection $conn, $data) use ($server) {
            // SocketServer returns ready-to-send frames (each protocol owns its
            // own framing — GamePlatform prefixes `:::`, Amatic sends raw hex).
            foreach ($this->safeHandle($server, (string) $data) as $message) {
                $conn->send($message);
            }
        };

        // Workerman reads the process verb from the global argv.
        global $argv;
        $argv = ['artisan', $this->verb()];
        if ($this->option('daemon') && $this->verb() === 'start') {
            $argv[] = '-d';
        }
        $_SERVER['argv'] = $argv;

        if ($this->verb() === 'start') {
            $this->info("game:socket listening on ws://{$host}:{$port} ({$worker->count} worker".($worker->count > 1 ? 's' : '').')');
        }

        Worker::runAll();

        return self::SUCCESS;
    }

    private function verb(): string
    {
        return match (true) {
            (bool) $this->option('stop') => 'stop',
            (bool) $this->option('restart') => 'restart',
            (bool) $this->option('status') => 'status',
            (bool) $this->option('reload') => 'reload',
            default => 'start',
        };
    }

    /** @return list<string> */
    private function safeHandle(SocketServer $server, string $frame): array
    {
        try {
            return $server->handle($frame);
        } catch (Throwable $e) {
            try {
                DB::reconnect();
            } catch (Throwable) {
            }
            report($e);
            $this->log('error: '.$e->getMessage());

            return [json_encode(['responseEvent' => 'error', 'responseType' => '', 'serverResponse' => 'InternalError'])];
        }
    }

    private function log(string $line): void
    {
        fwrite(STDERR, '['.now()->toDateTimeString()."] game-socket: {$line}\n");
    }
}
