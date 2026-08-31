<?php

namespace App\Filament\Resources\GameRounds;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Concerns\ScopesToViewer;
use App\Filament\Resources\GameRounds\Pages\ListGameRounds;
use App\Filament\Resources\GameRounds\Pages\ViewGameRound;
use App\Filament\Resources\GameRounds\Schemas\GameRoundForm;
use App\Filament\Resources\GameRounds\Schemas\GameRoundInfolist;
use App\Filament\Resources\GameRounds\Tables\GameRoundsTable;
use App\Models\GameRound;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GameRoundResource extends Resource
{
    use AuthorizesWithPermission;
    use ScopesToViewer;

    protected static ?string $model = GameRound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Game Round';

    protected static ?string $permission = 'stats.game';

    protected static bool $readOnly = true;

    public static function form(Schema $schema): Schema
    {
        return GameRoundForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GameRoundInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GameRoundsTable::configure($table);
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
            'index' => ListGameRounds::route('/'),
            'view' => ViewGameRound::route('/{record}'),
        ];
    }
}
