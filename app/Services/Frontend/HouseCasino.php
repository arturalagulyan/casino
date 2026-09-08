<?php

namespace App\Services\Frontend;

use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Game;
use App\Models\Shop;
use App\Models\User;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The first-party player frontend, modelled as an API client.
 *
 * It owns one {@see Shop} (`config('frontend.shop_slug')`) and one {@see ApiKey},
 * and resolves games / mints launch tokens through the very same
 * {@see GameLaunch} service external operators call over HTTP — only here the
 * player is already session-authenticated, so it runs in-process.
 */
class HouseCasino
{
    private ?Shop $shop = null;

    public function __construct(private readonly GameLaunch $launcher) {}

    public function shop(): Shop
    {
        return $this->shop ??= Shop::query()
            ->where('slug', config('frontend.shop_slug'))
            ->firstOr(fn () => throw new RuntimeException(
                'Player frontend shop is not set up. Run `php artisan frontend:setup`.',
            ));
    }

    public function apiKey(): ApiKey
    {
        /** @var ApiKey */
        return $this->shop()->apiKeys()
            ->where('is_active', true)
            ->firstOr(fn () => throw new RuntimeException(
                'Player frontend has no active API key. Run `php artisan frontend:setup`.',
            ));
    }

    /** Base query for the visible games in the house catalogue. */
    public function games(): Builder
    {
        return Game::query()
            ->where('shop_id', $this->shop()->id)
            ->where('is_visible', true)
            ->with(['template:id,code,title,poster_path', 'categories:id,title,slug']);
    }

    /** Categories that carry at least one visible house game, with their counts. */
    public function categories(): Collection
    {
        return Category::query()
            ->whereHas('games', fn (Builder $q) => $q
                ->where('games.shop_id', $this->shop()->id)
                ->where('games.is_visible', true))
            ->withCount(['games as games_count' => fn (Builder $q) => $q
                ->where('games.shop_id', $this->shop()->id)
                ->where('games.is_visible', true)])
            ->orderBy('position')
            ->orderBy('title')
            ->get();
    }

    /** One visible house game by its template code, or null. */
    public function game(string $code): ?Game
    {
        /** @var Game|null */
        return $this->games()
            ->whereHas('template', fn (Builder $q) => $q->where('code', $code))
            ->first();
    }

    /**
     * A fresh, 1-hour launch URL for this player to open the game — the URL an
     * external operator would receive from `POST /api/game/launch`.
     */
    public function launchUrl(User $player, string $code): string
    {
        abort_unless($player->shop_id === $this->shop()->id, 403, 'This player is not on the house shop.');

        try {
            $game = $this->launcher->resolveGame($this->apiKey(), $code);
        } catch (\Throwable) {
            abort(404, "Game [{$code}] is not available.");
        }

        return $this->launcher->launchUrl(
            $this->launcher->issueToken($player, $game),
            $game->template->code,
        );
    }
}
