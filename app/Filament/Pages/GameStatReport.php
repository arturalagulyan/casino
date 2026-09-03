<?php

namespace App\Filament\Pages;

use App\Enums\Currency;
use App\Models\Game;
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
 * Per-game performance over a date range: spins, in/out, profit, actual RTP,
 * hold %. ← legacy Web\Backend\DashboardController@game_stat.
 */
class GameStatReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Game stats';

    protected string $view = 'filament.pages.report';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stats.game');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters): Collection => $this->rows($filters ?? []))
            ->columns([
                TextColumn::make('game')->label('Game')->weight('bold')->sortable(),
                TextColumn::make('shop')->badge()->color('gray')->sortable(),
                TextColumn::make('currency')->badge()->color('gray')->html()->formatStateUsing(fn ($state) => Currency::chipFor($state)),
                TextColumn::make('spins')->numeric()->alignEnd()->sortable(),
                TextColumn::make('in')
                    ->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('out')
                    ->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('profit')
                    ->alignEnd()->sortable()->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record['currency'])),
                TextColumn::make('rtp')
                    ->label('RTP %')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%'),
                TextColumn::make('hold')
                    ->label('Hold %')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%'),
            ])
            ->filters([
                SelectFilter::make('shop')
                    ->options(fn () => Shop::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('currency')->options(Currency::options()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->default(now()->subWeek()),
                        DatePicker::make('until')->default(now()),
                    ])
                    ->indicateUsing(fn (array $data) => array_filter($data)
                        ? 'Period: '.($data['from'] ?? '…').' → '.($data['until'] ?? '…')
                        : null),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->paginated([25, 50, 100])
            ->defaultSort('in', 'desc');
    }

    /** @param array<string, mixed> $filters @return Collection<int, array<string, mixed>> */
    protected function rows(array $filters): Collection
    {
        $from = Carbon::parse(($filters['period']['from'] ?? null) ?: now()->subWeek())->startOfDay();
        $until = Carbon::parse(($filters['period']['until'] ?? null) ?: now())->endOfDay();
        $currency = $filters['currency']['value'] ?? null;
        $shop = $filters['shop']['value'] ?? null;
        $shopIds = Hierarchy::visibleShopIds(auth()->user());

        $agg = DB::table('game_rounds')
            ->selectRaw('game_id, game_code, shop_id, currency, SUM(bet) AS bet, SUM(win) AS win, COUNT(*) AS spins')
            ->whereBetween('played_at', [$from, $until])
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->when($shop, fn ($q) => $q->where('shop_id', $shop))
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds ?: [0]))
            ->groupBy('game_id', 'game_code', 'shop_id', 'currency')
            ->limit(1000)
            ->get();

        $games = Game::whereIn('id', $agg->pluck('game_id')->unique()->filter())
            ->with('template:id,title')
            ->get()
            ->keyBy('id');
        $shops = Shop::whereIn('id', $agg->pluck('shop_id')->unique()->filter())->pluck('name', 'id');

        return $agg->map(function ($r) use ($games, $shops): array {
            $bet = (float) $r->bet;
            $win = (float) $r->win;
            $game = $games->get($r->game_id);

            return [
                'game' => $game ? ($game->title ?: $game->template->title) : $r->game_code,
                'shop' => $shops->get($r->shop_id) ?? "#{$r->shop_id}",
                'currency' => (string) $r->currency,
                'spins' => (int) $r->spins,
                'in' => $bet,
                'out' => $win,
                'profit' => $bet - $win,
                'rtp' => $bet > 0 ? $win / $bet * 100 : 0.0,
                'hold' => $bet > 0 ? ($bet - $win) / $bet * 100 : 0.0,
            ];
        })->values();
    }
}
