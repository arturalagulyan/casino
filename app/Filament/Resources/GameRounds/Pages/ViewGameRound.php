<?php

namespace App\Filament\Resources\GameRounds\Pages;

use App\Filament\Resources\GameRounds\GameRoundResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGameRound extends ViewRecord
{
    protected static string $resource = GameRoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
