<?php

namespace App\Filament\Resources\Jackpots;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Concerns\ScopesToViewer;
use App\Filament\Resources\Jackpots\Pages\CreateJackpot;
use App\Filament\Resources\Jackpots\Pages\EditJackpot;
use App\Filament\Resources\Jackpots\Pages\ListJackpots;
use App\Filament\Resources\Jackpots\Pages\ViewJackpot;
use App\Filament\Resources\Jackpots\Schemas\JackpotForm;
use App\Filament\Resources\Jackpots\Schemas\JackpotInfolist;
use App\Filament\Resources\Jackpots\Tables\JackpotsTable;
use App\Models\Jackpot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class JackpotResource extends Resource
{
    use AuthorizesWithPermission;
    use ScopesToViewer;

    protected static ?string $model = Jackpot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|\UnitEnum|null $navigationGroup = 'Games';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Jackpot';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $permission = 'jpgame.manage';

    public static function form(Schema $schema): Schema
    {
        return JackpotForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JackpotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JackpotsTable::configure($table);
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
            'index' => ListJackpots::route('/'),
            'create' => CreateJackpot::route('/create'),
            'view' => ViewJackpot::route('/{record}'),
            'edit' => EditJackpot::route('/{record}/edit'),
        ];
    }
}
