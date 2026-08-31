<?php

namespace App\Filament\Support;

use App\Enums\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable table filters so every list in the panel filters the same way the
 * legacy backend did (see docs/BUSINESS-LOGIC-REVIEW.md §2).
 */
class TableFilters
{
    /** Currency dropdown (legacy shop-list / cash-report currency filter). */
    public static function currency(string $attribute = 'currency'): SelectFilter
    {
        return SelectFilter::make($attribute)
            ->label('Currency')
            ->options(Currency::options())
            ->attribute($attribute);
    }

    /**
     * Numeric "from / to" range (legacy credit_from/credit_to, percent_from/to).
     */
    public static function amountRange(string $attribute, ?string $label = null): Filter
    {
        $label ??= str($attribute)->headline()->toString();

        return Filter::make($attribute.'_range')
            ->schema([
                TextInput::make('from')->label("{$label} from")->numeric(),
                TextInput::make('to')->label("{$label} to")->numeric(),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] !== null && $data['from'] !== '',
                    fn (Builder $q) => $q->where($attribute, '>=', $data['from']))
                ->when($data['to'] !== null && $data['to'] !== '',
                    fn (Builder $q) => $q->where($attribute, '<=', $data['to'])))
            ->indicateUsing(function (array $data) use ($label): ?string {
                $from = $data['from'] ?? null;
                $to = $data['to'] ?? null;

                if (($from === null || $from === '') && ($to === null || $to === '')) {
                    return null;
                }

                return trim("{$label}: ".($from !== '' && $from !== null ? "≥ {$from}" : '')
                    .' '.($to !== '' && $to !== null ? "≤ {$to}" : ''));
            });
    }

    /** Date "from / until" range (legacy cash-report date filter, stat filters). */
    public static function dateRange(string $attribute = 'created_at', string $label = 'Date'): Filter
    {
        return Filter::make($attribute.'_between')
            ->schema([
                DatePicker::make('from')->label("{$label} from"),
                DatePicker::make('until')->label("{$label} until"),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null,
                    fn (Builder $q, $date) => $q->whereDate($attribute, '>=', $date))
                ->when($data['until'] ?? null,
                    fn (Builder $q, $date) => $q->whereDate($attribute, '<=', $date)))
            ->indicateUsing(function (array $data) use ($label): ?string {
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;

                if (! $from && ! $until) {
                    return null;
                }

                return trim("{$label}: ".($from ? "from {$from}" : '').' '.($until ? "until {$until}" : ''));
            });
    }
}
