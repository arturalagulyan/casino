<?php

namespace App\Filament\Resources\Categories\Schemas;

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
                TextInput::make('position')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
