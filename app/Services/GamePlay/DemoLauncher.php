<?php

namespace App\Services\GamePlay;

use App\Enums\UserStatus;
use App\Models\Game;
use App\Models\Shop;
use App\Models\User;
use App\Services\SeamlessWallet\GameLaunch;

/**
 * Spins up a throwaway "demo" player for a shop and hands back a launch URL, so
 * staff can test-play any game straight from the admin panel with fake credits.
 *
 * Demo players carry `free_demo = true`; {@see GameContext} keeps their whole
 * session off the books — no bank, no jackpots, no transactions, no game_rounds,
 * no RTP stats. Each launch resets the demo wallet to a fresh bankroll.
 */
class DemoLauncher
{
    /** Fresh fake bankroll handed to the demo player on every launch. */
    public const float BANKROLL = 5000.0;

    /** Reserved per-shop username for the demo account. */
    private const string USERNAME = '__demo';

    public function __construct(private readonly GameLaunch $launcher) {}

    /** A shareable launch URL for this game, played as the shop's demo player. */
    public function launchUrl(Game $game): string
    {
        $game->loadMissing('shop', 'template');
        $player = $this->player($game->shop);

        $player->wallet()->update([
            'currency' => $player->currency ?? $game->shop->currency,
            'balance' => self::BANKROLL,
        ]);

        return $this->launcher->launchUrl(
            $this->launcher->issueToken($player, $game),
            $game->template->code,
        );
    }

    /** Get (or create) the shop's demo player. */
    public function player(Shop $shop): User
    {
        /** @var User $user */
        $user = User::withTrashed()->firstOrNew([
            'shop_id' => $shop->id,
            'username' => self::USERNAME,
        ]);

        $user->fill([
            'first_name' => 'Demo',
            'last_name' => 'Player',
            'currency' => $shop->currency,
            'status' => UserStatus::Active,
            'free_demo' => true,
        ]);

        if (! $user->exists) {
            $user->password = bcrypt(str()->random(40));
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $user->save();

        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }

        return $user->refresh();
    }
}
