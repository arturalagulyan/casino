<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Textarea;

/**
 * A Textarea bound to a JSON-cast model column: shows pretty-printed JSON,
 * saves a decoded array (or null when blank / invalid-but-empty).
 */
class JsonField
{
    public static function make(string $name, ?string $label = null): Textarea
    {
        return Textarea::make($name)
            ->label($label ?? str($name)->headline())
            ->rows(8)
            ->extraInputAttributes(['class' => 'font-mono text-xs', 'spellcheck' => 'false', 'style' => 'max-height:22rem'])
            ->rule('json')
            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? json_decode($state, true) : null)
            ->formatStateUsing(fn ($state) => filled($state)
                ? (is_string($state) ? $state : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : null);
    }
}
