<?php

namespace App\Filament\Actions;

use App\Models\Game;
use App\Models\GameTemplate;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * "Play demo" — opens the game in a new tab, played as the shop's throwaway
 * demo player (fake credits, nothing hits the books). Works on a Game record
 * (its own shop) and on a GameTemplate (DemoPlayController picks the shop, or
 * asks when the template has more than one).
 */
class PlayDemoAction
{
    public static function make(string $name = 'playDemo'): Action
    {
        return Action::make($name)
            ->label('Play demo')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (Model $record) => self::available($record))
            ->url(fn (Model $record) => self::url($record), shouldOpenInNewTab: true);
    }

    private static function available(Model $record): bool
    {
        if (! auth()->user()?->hasPermission('games.manage')) {
            return false;
        }

        $template = $record instanceof Game ? $record->template : $record;

        return $template instanceof GameTemplate && $template->activeBundle !== null;
    }

    private static function url(Model $record): string
    {
        if ($record instanceof Game) {
            return route('games.demo', ['code' => $record->template->code, 'shop' => $record->shop_id]);
        }

        /** @var GameTemplate $record */
        return route('games.demo', ['code' => $record->code]);
    }
}
