<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Forms\JsonField;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name'),
                Select::make('parent_id')
                    ->relationship('parent', 'title'),
                TextInput::make('template_id')
                    ->numeric(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('slug'),
                Select::make('type')
                    ->options(['provider' => 'Provider', 'category' => 'Category'])
                    ->default('provider')
                    ->helperText('Categories are providers (synced from Betboy: php artisan categories:sync-providers). Themes and labels are Tags.'),
                TextInput::make('external_ref')
                    ->label('External ref')
                    ->helperText('e.g. betboy:netent — set by the provider sync.'),
                FileUpload::make('image_path')
                    ->label('Icon')
                    ->disk('public')
                    ->directory('category-images')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])
                    ->maxSize(512),
                TextInput::make('position')
                    ->required()
                    ->numeric()
                    ->default(0),
                JsonField::make('config')
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText('Shared game config every game in this category inherits (a game or template can override any key). e.g. {"client_protocol":"game_platform"} routes its games to the GamePlatform WebSocket protocol. Also accepts "min_match", "layout", "bonus_config", …'),
            ]);
    }
}
