<?php

namespace App\Filament\Resources\GameBanks\Pages;

use App\Filament\Resources\GameBanks\GameBankResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameBanks extends ListRecords
{
    protected static string $resource = GameBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
