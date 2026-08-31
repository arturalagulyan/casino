<?php

namespace App\Filament\Resources\GameBanks\Pages;

use App\Filament\Resources\GameBanks\GameBankResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGameBank extends ViewRecord
{
    protected static string $resource = GameBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
