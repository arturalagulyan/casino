<?php

namespace App\Filament\Resources\Games;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Concerns\ScopesToViewer;
use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\ViewGame;
use App\Filament\Resources\Games\Schemas\GameForm;
use App\Filament\Resources\Games\Schemas\GameInfolist;
use App\Filament\Resources\Games\Tables\GamesTable;
use App\Models\Game;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GameResource extends Resource
{
    use AuthorizesWithPermission;
    use ScopesToViewer;

    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|\UnitEnum|null $navigationGroup = 'Games';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Game';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $permission = 'games.manage';

    public static function form(Schema $schema): Schema
    {
        return GameForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GameInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GamesTable::configure($table);
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
            'index' => ListGames::route('/'),
            'create' => CreateGame::route('/create'),
            'view' => ViewGame::route('/{record}'),
            'edit' => EditGame::route('/{record}/edit'),
        ];
    }
}
