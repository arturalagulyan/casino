<?php

namespace App\Filament\Resources\GameDesigns\Pages;

use App\Filament\Resources\GameDesigns\GameDesignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGameDesign extends CreateRecord
{
    protected static string $resource = GameDesignResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
