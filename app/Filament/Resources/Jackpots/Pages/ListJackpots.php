<?php

namespace App\Filament\Resources\Jackpots\Pages;

use App\Filament\Resources\Jackpots\JackpotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJackpots extends ListRecords
{
    protected static string $resource = JackpotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
