<?php

namespace App\Filament\Resources\GameTemplates\Pages;

use App\Filament\Actions\PlayDemoAction;
use App\Filament\Resources\GameTemplates\GameTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGameTemplate extends ViewRecord
{
    protected static string $resource = GameTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlayDemoAction::make(),
            EditAction::make(),
        ];
    }
}
