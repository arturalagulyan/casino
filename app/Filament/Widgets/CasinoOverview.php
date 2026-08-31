<?php

namespace App\Filament\Widgets;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\Shop;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class CasinoOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Today at the tables';

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        // GGR never crosses currencies — one card per currency with play today
        // (see docs/BUSINESS-LOGIC-REVIEW.md §1).
        $byCurrency = GameRound::query()
            ->where('played_at', '>=', $today)
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

        $stats[] = Stat::make('Players', Number::format(User::whereHas('roles', fn ($q) => $q->where('slug', 'user'))->count()))
            ->description(User::where('last_online_at', '>=', now()->subMinutes(15))->count().' online now')
            ->descriptionIcon('heroicon-m-user-group')
            ->color('primary');

        $funds = Wallet::query()
            ->selectRaw('currency, SUM(balance) AS total')
            ->groupBy('currency')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => Money::format($r->total, $r->currency))
            ->implode('  ·  ');

        $stats[] = Stat::make('Player balances', $funds ?: '—')
            ->description('held across all wallets')
            ->descriptionIcon('heroicon-m-wallet')
            ->color('warning');

        $stats[] = Stat::make('Shops', Number::format(Shop::where('status', 'active')->count()))
            ->description(Game::count().' games live')
            ->descriptionIcon('heroicon-m-building-storefront')
            ->color('info');

        return $stats;
    }

    /** GGR per hour for the last 12 hours (one currency), for the sparkline. */
    private function hourlyGgr(string $currency): array
    {
        return collect(range(11, 0))
            ->map(function (int $hoursAgo) use ($currency) {
                $from = now()->subHours($hoursAgo)->startOfHour();
                $to = (clone $from)->addHour();

                $row = GameRound::query()
                    ->where('currency', $currency)
                    ->whereBetween('played_at', [$from, $to])
                    ->selectRaw('SUM(bet) AS bet, SUM(win) AS win')
                    ->first();

                return round((float) $row->bet - (float) $row->win, 2);
            })
            ->all();
    }
}
