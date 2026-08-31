<?php

namespace App\Filament\Resources\ApiKeys;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Concerns\ScopesToViewer;
use App\Filament\Resources\ApiKeys\Pages\CreateApiKey;
use App\Filament\Resources\ApiKeys\Pages\EditApiKey;
use App\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Filament\Resources\ApiKeys\Pages\ViewApiKey;
use App\Filament\Resources\ApiKeys\Schemas\ApiKeyForm;
use App\Filament\Resources\ApiKeys\Schemas\ApiKeyInfolist;
use App\Filament\Resources\ApiKeys\Tables\ApiKeysTable;
use App\Models\ApiKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApiKeyResource extends Resource
{
    use AuthorizesWithPermission;
    use ScopesToViewer;

    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'API Key';

    protected static ?string $pluralModelLabel = 'API Keys';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $permission = 'api.manage';

    public static function form(Schema $schema): Schema
    {
        return ApiKeyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApiKeyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiKeysTable::configure($table);
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
            'index' => ListApiKeys::route('/'),
            'create' => CreateApiKey::route('/create'),
            'view' => ViewApiKey::route('/{record}'),
            'edit' => EditApiKey::route('/{record}/edit'),
        ];
    }
}
