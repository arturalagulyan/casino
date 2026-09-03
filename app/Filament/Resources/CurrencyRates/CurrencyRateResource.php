<?php

namespace App\Filament\Resources\CurrencyRates;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\CurrencyRates\Pages\ManageCurrencyRates;
use App\Filament\Resources\CurrencyRates\Schemas\CurrencyRateForm;
use App\Filament\Resources\CurrencyRates\Tables\CurrencyRatesTable;
use App\Models\CurrencyRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * FX rates (units per 1 EUR) — the table App\Services\Fx reads. Jackpot pools
 * and per-currency bet ladders convert through these. Admin-editable until a
 * live feed replaces it.
 */
class CurrencyRateResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = CurrencyRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'currency';

    public static function form(Schema $schema): Schema
    {
        return CurrencyRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurrencyRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencyRates::route('/'),
        ];
    }
}
