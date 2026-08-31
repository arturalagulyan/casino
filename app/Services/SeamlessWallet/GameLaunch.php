<?php

namespace App\Services\SeamlessWallet;

use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Models\ApiKey;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Clean re-implementation of legacy PlayersController::getGameLaunch.
 *
 * Fixes the legacy problems: no hard-coded encrypt key/iv (uses app key via
 * Crypt), no hard-coded shop_id / role_id, validated input, player keyed by
 * (shop, external id) not a global `player` string.
 *
 * Game serving itself (runGame / runServer) is the spin-engine phase — `play()`
 * here only proves the token round-trips.
 */
class GameLaunch
{
    private const TTL_SECONDS = 3600;

    /** Upsert the provider's player inside the key's shop and sync their balance. */
    public function resolvePlayer(ApiKey $apiKey, array $data): User
    {
        $shop = $apiKey->shop;

        if (! $shop) {
            throw new RuntimeException('API key is not attached to a shop.');
        }

        $currency = Currency::tryFrom($data['currency'] ?? '') ?? $shop->currency ?? Currency::default();

        $user = User::firstOrNew([
            'shop_id' => $shop->id,
            'external_player_id' => (string) $data['player_id'],
        ]);

        $user->fill([
            'external_provider' => 'api:'.$apiKey->id,
            'username' => $user->username ?: $this->uniqueUsername($shop->id, $data['player_name'] ?? ('player'.$data['player_id'])),
            'email' => $data['email'] ?? $user->email,
            'currency' => $currency,
            'status' => UserStatus::Active,
        ]);

        if (! $user->exists) {
            $user->password = bcrypt(str()->random(40));
        }

        $user->save();

        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }

        // Seamless wallet: the provider is the source of truth for the balance.
        $user->wallet()->update([
            'currency' => $currency,
            'balance' => round((float) ($data['balance'] ?? 0), 4),
        ]);

        return $user->refresh();
    }

    /** Find the shop's instance of the requested game (by template code or game id). */
    public function resolveGame(ApiKey $apiKey, string|int $gameRef): Game
    {
        $query = Game::query()->where('shop_id', $apiKey->shop_id)->where('is_visible', true);

        $game = is_numeric($gameRef)
            ? $query->whereKey($gameRef)->first()
            : $query->whereHas('template', fn ($q) => $q->where('code', $gameRef))->first();

        if (! $game) {
            throw new RuntimeException("Game [{$gameRef}] is not available in this shop.");
        }

        return $game;
    }

    public function issueToken(User $user, Game $game): string
    {
        return Crypt::encryptString(json_encode([
            'u' => $user->id,
            'g' => $game->id,
            'exp' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ]));
    }

    /** @return array{user: User, game: Game} */
    public function verifyToken(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid launch token.');
        }

        if (($payload['exp'] ?? 0) < now()->timestamp) {
            throw new RuntimeException('Launch token has expired.');
        }

        $user = User::find($payload['u'] ?? null);
        $game = Game::find($payload['g'] ?? null);

        if (! $user || ! $game) {
            throw new RuntimeException('Launch token references a missing user or game.');
        }

        return ['user' => $user, 'game' => $game];
    }

    public function launchUrl(string $token): string
    {
        return URL::route('api.game.play', ['token' => $token]);
    }

    public function ttl(): int
    {
        return self::TTL_SECONDS;
    }

    private function uniqueUsername(int $shopId, string $base): string
    {
        $base = str($base)->slug()->limit(40, '')->toString() ?: 'player';
        $name = $base;
        $i = 1;

        while (User::where('shop_id', $shopId)->where('username', $name)->exists()) {
            $name = $base.'-'.$i++;
        }

        return $name;
    }
}
