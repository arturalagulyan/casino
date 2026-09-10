<?php

namespace App\Filament\Widgets;

use App\Models\Game;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\Hierarchy;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class CasinoOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Today at the tables';

    /** Shop ids to limit every figure to, or null for unrestricted (admin, no switcher pick). */
    private function shopIds(): ?array
    {
        return CurrentShop::narrow(Hierarchy::visibleShopIds(auth()->user()));
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $shopIds = $this->shopIds();

        // GGR never crosses currencies — one card per currency with play today
        // (see docs/BUSINESS-LOGIC-REVIEW.md §1).
        $byCurrency = DB::table('game_rounds')
            ->where('played_at', '>=', $today)
            ->when($shopIds !== null, fn (Builder $q) => $q->whereIn('shop_id', $shopIds ?: [0]))
            ->selectRaw('currency, SUM(bet) AS bet, SUM(win) AS win, COUNT(*) AS spins')
            ->groupBy('currency')
            ->orderByDesc('bet')
            ->get();

        $stats = [];

        foreach ($byCurrency as $row) {
            $currency = Money::currency($row->currency);
            $ggr = (float) $row->bet - (float) $row->win;

            $stats[] = Stat::make("GGR · {$currency->value}", Money::format($ggr, $currency))
                ->description($row->spins.' spins · '.Money::format($row->bet, $currency).' wagered')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($ggr >= 0 ? 'success' : 'danger')
                ->chart($this->hourlyGgr($currency->value));
        }

        if (empty($stats)) {
            $stats[] = Stat::make('GGR', '—')
                ->description('no spins yet today')
                ->color('gray');
        }

        $players = User::query()->players()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('users.shop_id', $shopIds ?: [0]));
        $online = User::query()->players()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('users.shop_id', $shopIds ?: [0]))
            ->where('last_online_at', '>=', now()->subMinutes(15));

        $stats[] = Stat::make('Players', Number::format($players->count()))
            ->description($online->count().' online now')
            ->descriptionIcon('heroicon-m-user-group')
            ->color('primary');

        $funds = DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->when($shopIds !== null, fn (Builder $q) => $q->whereIn('users.shop_id', $shopIds ?: [0]))
            ->selectRaw('wallets.currency AS currency, SUM(wallets.balance) AS total')
            ->groupBy('wallets.currency')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => Money::format($r->total, $r->currency))
            ->implode('  ·  ');

        $stats[] = Stat::make('Player balances', $funds ?: '—')
            ->description('held across all wallets')
            ->descriptionIcon('heroicon-m-wallet')
            ->color('warning');

        $shops = Shop::query()->where('status', 'active')
            ->when($shopIds !== null, fn ($q) => $q->whereIn('id', $shopIds ?: [0]));
        $games = Game::query()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds ?: [0]));

        $stats[] = Stat::make('Shops', Number::format($shops->count()))
            ->description($games->count().' games live')
            ->descriptionIcon('heroicon-m-building-storefront')
            ->color('info');

        return $stats;
    }

    /**
     * GGR per hour for the last 12 hours (one currency), for the sparkline.
     *
     * @return list<float>
     */
    private function hourlyGgr(string $currency): array
    {
        $shopIds = $this->shopIds();

        return collect(range(11, 0))
            ->map(function (int $hoursAgo) use ($currency, $shopIds): float {
                $from = now()->subHours($hoursAgo)->startOfHour();
                $to = (clone $from)->addHour();

                $row = DB::table('game_rounds')
                    ->where('currency', $currency)
                    ->whereBetween('played_at', [$from, $to])
                    ->when($shopIds !== null, fn (Builder $q) => $q->whereIn('shop_id', $shopIds ?: [0]))
                    ->selectRaw('COALESCE(SUM(bet), 0) AS bet, COALESCE(SUM(win), 0) AS win')
                    ->first();

                return round((float) $row->bet - (float) $row->win, 2);
            })
            ->all();
    }
}
