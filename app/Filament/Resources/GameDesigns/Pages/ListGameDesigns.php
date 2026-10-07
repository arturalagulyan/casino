<?php

namespace App\Filament\Resources\GameDesigns\Pages;

use App\Filament\Actions\GameDesignActions;
use App\Filament\Resources\GameDesigns\GameDesignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameDesigns extends ListRecords
{
    protected static string $resource = GameDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GameDesignActions::fromPreset(),
            GameDesignActions::importShipped(),
            CreateAction::make()->label('Blank design'),
        ];
    }
}
