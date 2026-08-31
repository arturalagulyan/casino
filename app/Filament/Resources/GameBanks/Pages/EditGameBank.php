<?php

namespace App\Filament\Resources\GameBanks\Pages;

use App\Filament\Resources\GameBanks\GameBankResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGameBank extends EditRecord
{
    protected static string $resource = GameBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
