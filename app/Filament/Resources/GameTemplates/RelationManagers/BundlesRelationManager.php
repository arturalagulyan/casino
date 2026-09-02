<?php

namespace App\Filament\Resources\GameTemplates\RelationManagers;

use App\Filament\Actions\UploadGameBundleAction;
use App\Models\GameBundle;
use App\Services\GamePlay\BundleManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BundlesRelationManager extends RelationManager
{
    protected static string $relationship = 'bundles';

    protected static ?string $title = 'Front-end bundles';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}")->badge(),
                IconColumn::make('is_active')->label('Live')->boolean(),
                TextColumn::make('entry'),
                TextColumn::make('file_count')->label('Files')->numeric(),
                TextColumn::make('size')
                    ->formatStateUsing(fn ($state) => number_format($state / 1048576, 1).' MB'),
                TextColumn::make('uploader.username')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->label('Uploaded'),
                TextColumn::make('notes')->placeholder('—')->wrap(),
            ])
            ->headerActions([
                UploadGameBundleAction::make()
                    ->label('Upload new version')
                    ->record(fn (BundlesRelationManager $livewire) => $livewire->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (GameBundle $record) => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (GameBundle $record) {
                        app(BundleManager::class)->activate($record);
                        Notification::make()->success()->title("v{$record->version} is now live")->send();
                    }),
                Action::make('delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (GameBundle $record) => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (GameBundle $record) {
                        try {
                            app(BundleManager::class)->delete($record);
                            Notification::make()->success()->title('Bundle deleted')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not deleted')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
