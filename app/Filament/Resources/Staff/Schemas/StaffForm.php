<?php

namespace App\Filament\Resources\Staff\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user.phone_number')
                    ->label('Phone Number')
                    ->tel()
                    ->required(),
                TextInput::make('user.name')
                    ->label('Full Name')
                    ->required(),
                TextInput::make('user.email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('address')
                    ->default(null),
                TextInput::make('user.password')
                    ->label('Password (Optional on update)')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => !empty($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state)),
                TextInput::make('hpcz_number')
                    ->default(null),


                Select::make('is_approved')
                    ->label('Approval Status')
                    ->required()
                    ->options([
                        2 => 'Pending',
                        1 => 'Approved',
                        3 => 'Rejected',
                    ])

            ]);
    }
}
