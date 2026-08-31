<?php

namespace App\Filament\Resources\GameBanks;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Concerns\ScopesToViewer;
use App\Filament\Resources\GameBanks\Pages\CreateGameBank;
use App\Filament\Resources\GameBanks\Pages\EditGameBank;
use App\Filament\Resources\GameBanks\Pages\ListGameBanks;
use App\Filament\Resources\GameBanks\Pages\ViewGameBank;
use App\Filament\Resources\GameBanks\Schemas\GameBankForm;
use App\Filament\Resources\GameBanks\Schemas\GameBankInfolist;
use App\Filament\Resources\GameBanks\Tables\GameBanksTable;
use App\Models\GameBank;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GameBankResource extends Resource
{
    use AuthorizesWithPermission;
    use ScopesToViewer;

    protected static ?string $model = GameBank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Game Bank';

    protected static ?string $permission = 'games.rtp';

    public static function form(Schema $schema): Schema
    {
        return GameBankForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GameBankInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GameBanksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGameBanks::route('/'),
            'create' => CreateGameBank::route('/create'),
            'view' => ViewGameBank::route('/{record}'),
            'edit' => EditGameBank::route('/{record}/edit'),
        ];
    }
}
