<?php

namespace App\Filament\Resources\GameDesigns\Pages;

use App\Filament\Actions\GameDesignActions;
use App\Filament\Resources\GameDesigns\GameDesignResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGameDesign extends EditRecord
{
    protected static string $resource = GameDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GameDesignActions::publish(),
            GameDesignActions::playDemo(),
            GameDesignActions::check(),
            DeleteAction::make(),
        ];
    }
}
