<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'მომხმარებლები';

    protected static ?string $modelLabel = 'მომხმარებელი';

    protected static ?int $navigationSort = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('სახელი')->required()->maxLength(255),
            TextInput::make('email')->label('ელფოსტა')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->label('პაროლი')->password()->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn ($state): bool => filled($state))->minLength(8),
            Select::make('role')->label('როლი')->options([
                User::ROLE_OWNER => 'Owner',
                User::ROLE_ADMINISTRATOR => 'Administrator',
                User::ROLE_LAB_TECHNICIAN => 'Lab Technician',
            ])->required(),
            Select::make('locale')->label('ენა')->options(['ka' => 'ქართული', 'en' => 'English'])->default('ka')->required(),
            Toggle::make('is_active')->label('აქტიურია')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('სახელი')->searchable()->sortable(),
            TextColumn::make('email')->label('ელფოსტა')->searchable(),
            TextColumn::make('role')->label('როლი')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                User::ROLE_OWNER => 'Owner',
                User::ROLE_ADMINISTRATOR => 'Administrator',
                User::ROLE_LAB_TECHNICIAN => 'Lab Technician',
                default => $state,
            }),
            IconColumn::make('is_active')->label('აქტიურია')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
