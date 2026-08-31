<?php

namespace App\Filament\Actions;

use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/** Roll a new 25-char key (legacy ApiController@generate). */
class RegenerateApiKeyAction
{
    public static function make(string $name = 'regenerate'): Action
    {
        return Action::make($name)
            ->label('Regenerate key')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The provider must be given the new key — the old one stops working immediately.')
            ->action(function (ApiKey $record) {
                $record->update(['key' => Str::random(25)]);

                Notification::make()->success()->title('New key issued')->body($record->key)->send();
            });
    }
}
