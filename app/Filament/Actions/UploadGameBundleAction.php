<?php

namespace App\Filament\Actions;

use App\Models\GameTemplate;
use App\Services\GamePlay\BundleManager;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Admin uploads the game's front-end files (a .zip of the legacy
 * public/games/<Code> folder). Extracted to the game_bundles disk and made the
 * active version.
 */
class UploadGameBundleAction
{
    public static function make(string $name = 'uploadBundle'): Action
    {
        return Action::make($name)
            ->label('Upload front-end')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->visible(fn () => (bool) auth()->user()?->hasPermission('games.manage'))
            ->modalWidth('lg')
            ->schema([
                FileUpload::make('archive')
                    ->label('Bundle (.zip)')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip'])
                    ->storeFiles(false)
                    ->required()
                    ->helperText('A zip of the game front-end. index.html must be at the root (a single wrapping folder is unwrapped). No PHP files.'),
                TextInput::make('entry')
                    ->label('Entry file')
                    ->placeholder('index.html')
                    ->helperText('Only if the launch file is not index.html.'),
                TextInput::make('notes')->maxLength(255),
            ])
            ->action(function (array $data, GameTemplate $record) {
                try {
                    $bundle = app(BundleManager::class)->store(
                        $record,
                        $data['archive'],
                        auth()->user(),
                        $data['entry'] ?: null,
                        $data['notes'] ?: null,
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Upload failed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Bundle v{$bundle->version} is live")
                    ->body("{$bundle->file_count} files · ".number_format($bundle->size / 1048576, 1).' MB · entry '.$bundle->entry)
                    ->send();
            });
    }
}
