<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Actions\PlayDemoAction;
use App\Filament\Resources\Games\GameResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGame extends ViewRecord
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlayDemoAction::make(),
            EditAction::make(),
        ];
    }
}
