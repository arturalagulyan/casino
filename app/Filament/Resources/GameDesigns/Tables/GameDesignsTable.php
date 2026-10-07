<?php

namespace App\Filament\Resources\GameDesigns\Tables;

use App\Filament\Actions\GameDesignActions;
use App\Models\GameDesign;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GameDesignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('template.poster_path')->label('')->disk('public')->square()->imageSize(48),
                TextColumn::make('title')->searchable()->sortable()->description(fn (GameDesign $r) => $r->code),
                TextColumn::make('mechanic')->badge()
                    ->formatStateUsing(fn (string $state) => $state === GameDesign::MECHANIC_CASCADE ? 'Pay anywhere' : 'Paylines'),
                TextColumn::make('grid')->state(fn (GameDesign $r) => $r->reel_count.' x '.$r->row_count),
                TextColumn::make('skin')->badge()->color('gray'),
                TextColumn::make('art_pack')->label('Art')->toggleable(),
                TextColumn::make('published_at')->label('Published')->since()->sortable()->placeholder('draft'),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                GameDesignActions::publish(),
                GameDesignActions::playDemo(),
                DeleteAction::make(),
            ]);
    }
}
