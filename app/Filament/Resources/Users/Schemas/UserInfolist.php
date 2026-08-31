<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop')
                    ->placeholder('-'),
                TextEntry::make('role.name')
                    ->label('Role')
                    ->placeholder('-'),
                TextEntry::make('parent.id')
                    ->label('Parent')
                    ->placeholder('-'),
                TextEntry::make('inviter.id')
                    ->label('Inviter')
                    ->placeholder('-'),
                TextEntry::make('username')
                    ->placeholder('-'),
                TextEntry::make('email')
                    ->label('Email address')
                    ->placeholder('-'),
                TextEntry::make('first_name')
                    ->placeholder('-'),
                TextEntry::make('last_name')
                    ->placeholder('-'),
                TextEntry::make('phone')
                    ->placeholder('-'),
                TextEntry::make('phone_verified_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('birthday')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('avatar')
                    ->placeholder('-'),
                TextEntry::make('language'),
                TextEntry::make('currency')
                    ->placeholder('-'),
                TextEntry::make('rating')
                    ->numeric(),
                TextEntry::make('status')
                    ->badge(),
                IconEntry::make('is_blocked')
                    ->boolean(),
                IconEntry::make('is_demo_agent')
                    ->boolean(),
                IconEntry::make('free_demo')
                    ->boolean(),
                TextEntry::make('agreed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('external_provider')
                    ->placeholder('-'),
                TextEntry::make('external_player_id')
                    ->placeholder('-'),
                TextEntry::make('two_factor_secret')
                    ->placeholder('-'),
                IconEntry::make('two_factor_enabled')
                    ->boolean(),
                TextEntry::make('current_session_id')
                    ->placeholder('-'),
                TextEntry::make('sms_token_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_login_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_online_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_bet_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_progress_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_daily_entry_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_wheel_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (User $record): bool => $record->trashed()),
            ]);
    }
}
