<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Actions\PlayDemoAction;
use App\Filament\Resources\Games\GameResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlayDemoAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
