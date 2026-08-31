<?php

namespace App\Filament\Resources\Jackpots\Pages;

use App\Filament\Resources\Jackpots\JackpotResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewJackpot extends ViewRecord
{
    protected static string $resource = JackpotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
