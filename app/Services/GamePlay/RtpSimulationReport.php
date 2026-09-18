<?php

namespace App\Services\GamePlay;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists a {@see RtpSimulator::run()} result to disk so the admin can come
 * back and view/download it — a "Test RTP" run can produce thousands of rows,
 * far too much to carry in a flash notification. Storage is a plain JSON file
 * on the private local disk, keyed by a random token; there is no DB table for
 * this because these are throwaway debugging artifacts, not audited records.
 */
class RtpSimulationReport
{
    private const string DISK = 'local';

    private const string DIR = 'rtp-simulations';

    /** How long a report stays downloadable before it's swept away. */
    private const int TTL_HOURS = 72;

    /** @param array<string, mixed> $result {@see RtpSimulator::run()} */
    public function store(array $result): string
    {
        $token = (string) Str::uuid();

        Storage::disk(self::DISK)->put(
            self::DIR."/{$token}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );

        $this->prune();

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function find(string $token): ?array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $token)) {
            return null;
        }

        $path = self::DIR."/{$token}.json";
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $result = json_decode($disk->get($path), true);

        return is_array($result) ? $result : null;
    }

    /** CSV text for a stored result — one row per spin, same fields as the game rounds tab plus the RTP split. */
    public function toCsv(array $result): string
    {
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            'Spin', 'Game', 'Shop', 'Currency', 'Bet', 'Win', 'P/L', 'Balance',
            'Game in', 'Jackpot in', 'Profit',
        ]);

        foreach ($result['rows'] ?? [] as $row) {
            fputcsv($stream, [
                $row['spin'],
                $result['game_name'] ?? '',
                $result['shop_name'] ?? '',
                $result['currency'] ?? '',
                $row['bet'],
                $row['win'],
                $row['net'],
                $row['balance_after'],
                $row['game_in'],
                $row['jackpot_in'],
                $row['profit'],
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Best-effort sweep of expired reports — runs on every store() so no scheduler entry is needed. */
    private function prune(): void
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subHours(self::TTL_HOURS)->getTimestamp();

        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
