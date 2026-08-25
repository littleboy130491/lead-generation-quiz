<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->label('Email address')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->password()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state))->maxLength(255)->helperText('Leave blank when retaining the current password.'),
            Select::make('roles')->relationship('roles', 'name')->multiple()->preload()->searchable()->required()->helperText('Roles determine all panel access and actions.'),
            DateTimePicker::make('email_verified_at'),
        ]);
    }
}
