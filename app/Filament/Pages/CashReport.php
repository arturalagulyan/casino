<?php

namespace App\Filament\Pages;

use App\Enums\Currency;
use App\Models\Shop;
use App\Support\Hierarchy;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-shop turnover: in (bet) / out (win) / net / payout %, over a date range,
 * optionally filtered to one currency. ← legacy Web\Backend\CashController.
 * Rows are keyed by (shop, currency) — figures never cross currencies.
 */
class CashReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Cash report';

    protected string $view = 'filament.pages.report';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stats.pay');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters): Collection => $this->rows($filters ?? []))
            ->columns([
                TextColumn::make('shop')->label('Shop')->weight('bold')->sortable(),
                TextColumn::make('currency')->badge()->color('gray')->html()->formatStateUsing(fn ($state) => Currency::chipFor($state)),
                TextColumn::make('spins')->numeric()->alignEnd()->sortable(),
                TextColumn::make('in')
                    ->label('In (bet)')
                    ->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('out')
                    ->label('Out (win)')
                    ->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('net')
                    ->alignEnd()->sortable()->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('payout')
                    ->label('Payout %')
                    ->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%'),
            ])
            ->filters([
                SelectFilter::make('currency')
                    ->options(Currency::options())
                    ->label('Currency'),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->default(now()->subMonth()),
                        DatePicker::make('until')->default(now()),
                    ])
                    ->indicateUsing(fn (array $data) => array_filter($data)
                        ? 'Period: '.($data['from'] ?? '…').' → '.($data['until'] ?? '…')
                        : null),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->paginated([25, 50, 100])
            ->defaultSort('shop');
    }

    /** @param array<string, mixed> $filters @return Collection<int, array<string, mixed>> */
    protected function rows(array $filters): Collection
    {
        [$from, $until] = $this->period($filters);
        $currency = $filters['currency']['value'] ?? null;
        $shopIds = Hierarchy::visibleShopIds(auth()->user());

        $agg = DB::table('game_rounds')
            ->selectRaw('shop_id, currency, SUM(bet) AS bet, SUM(win) AS win, COUNT(*) AS spins')
            ->whereBetween('played_at', [$from, $until])
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds ?: [0]))
            ->groupBy('shop_id', 'currency')
            ->get();

        $shops = Shop::whereIn('id', $agg->pluck('shop_id')->unique()->filter())->pluck('name', 'id');

        return $agg->map(function ($r) use ($shops): array {
            $bet = (float) $r->bet;
            $win = (float) $r->win;

            return [
                'shop' => $shops->get($r->shop_id) ?? "#{$r->shop_id}",
                'currency' => (string) $r->currency,
                'spins' => (int) $r->spins,
                'in' => $bet,
                'out' => $win,
                'net' => $bet - $win,
                'payout' => $bet > 0 ? $win / $bet * 100 : 0.0,
            ];
        })->values();
    }

    /** @return array<string, array{in: float, out: float, net: float}> */
    public function totals(): array
    {
        $totals = [];

        foreach ($this->rows($this->tableFilters ?? []) as $row) {
            $c = $row['currency'];
            $totals[$c] ??= ['in' => 0, 'out' => 0, 'net' => 0];
            $totals[$c]['in'] += $row['in'];
            $totals[$c]['out'] += $row['out'];
            $totals[$c]['net'] += $row['net'];
        }

        return $totals;
    }

    public function fmt(float|int|string $amount, string $currency): string
    {
        return Money::format($amount, $currency);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(array $filters): array
    {
        $from = $filters['period']['from'] ?? null;
        $until = $filters['period']['until'] ?? null;

        return [
            Carbon::parse($from ?: now()->subMonth())->startOfDay(),
            Carbon::parse($until ?: now())->endOfDay(),
        ];
    }
}
