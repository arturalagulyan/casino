<?php

namespace App\Filament\Resources\GameDesigns;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\GameDesigns\Pages\CreateGameDesign;
use App\Filament\Resources\GameDesigns\Pages\EditGameDesign;
use App\Filament\Resources\GameDesigns\Pages\ListGameDesigns;
use App\Filament\Resources\GameDesigns\Schemas\GameDesignForm;
use App\Filament\Resources\GameDesigns\Tables\GameDesignsTable;
use App\Models\GameDesign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Game Builder — admins design their own RoyalSpin games (grid, symbols,
 * pays, features, look & feel, art) and publish them as playable games.
 */
class GameDesignResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = GameDesign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|\UnitEnum|null $navigationGroup = 'Games';

    protected static ?string $navigationLabel = 'Game Builder';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'game design';

    protected static ?string $pluralModelLabel = 'Game Builder';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $permission = 'games.manage';

    public static function form(Schema $schema): Schema
    {
        return GameDesignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GameDesignsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGameDesigns::route('/'),
            'create' => CreateGameDesign::route('/create'),
            'edit' => EditGameDesign::route('/{record}/edit'),
        ];
    }
}
