<?php

namespace App\Filament\Resources\GameTemplates\Pages;

use App\Filament\Resources\GameTemplates\GameTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameTemplates extends ListRecords
{
    protected static string $resource = GameTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
