<?php

namespace App\Filament\Resources\GameTemplates;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\GameTemplates\Pages\CreateGameTemplate;
use App\Filament\Resources\GameTemplates\Pages\EditGameTemplate;
use App\Filament\Resources\GameTemplates\Pages\ListGameTemplates;
use App\Filament\Resources\GameTemplates\Pages\ViewGameTemplate;
use App\Filament\Resources\GameTemplates\Schemas\GameTemplateForm;
use App\Filament\Resources\GameTemplates\Schemas\GameTemplateInfolist;
use App\Filament\Resources\GameTemplates\Tables\GameTemplatesTable;
use App\Models\GameTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GameTemplateResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = GameTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Games';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Game Template';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $permission = 'games.manage';

    public static function form(Schema $schema): Schema
    {
        return GameTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GameTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GameTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BundlesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGameTemplates::route('/'),
            'create' => CreateGameTemplate::route('/create'),
            'view' => ViewGameTemplate::route('/{record}'),
            'edit' => EditGameTemplate::route('/{record}/edit'),
        ];
    }
}
