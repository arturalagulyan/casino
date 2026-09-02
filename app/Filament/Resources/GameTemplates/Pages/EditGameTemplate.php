<?php

namespace App\Filament\Resources\GameTemplates\Pages;

use App\Filament\Actions\PlayDemoAction;
use App\Filament\Resources\GameTemplates\GameTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGameTemplate extends EditRecord
{
    protected static string $resource = GameTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlayDemoAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
