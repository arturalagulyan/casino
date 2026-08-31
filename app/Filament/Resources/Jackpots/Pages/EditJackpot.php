<?php

namespace App\Filament\Resources\Jackpots\Pages;

use App\Filament\Resources\Jackpots\JackpotResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditJackpot extends EditRecord
{
    protected static string $resource = JackpotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
