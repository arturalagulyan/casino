<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, Get $get) => blank($get('slug'))
                        ? $set('slug', Str::slug((string) $state))
                        : null),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('position')
                    ->numeric()
                    ->default(0)
                    ->required(),
                FileUpload::make('image_path')
                    ->label('Icon')
                    ->disk('public')
                    ->directory('category-images')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])
                    ->maxSize(512),
                Select::make('templates')
                    ->label('Games')
                    ->relationship('templates', 'title')
                    ->multiple()
                    ->searchable()
                    ->columnSpanFull(),
            ]);
    }
}
