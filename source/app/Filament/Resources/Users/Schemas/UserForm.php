<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Account')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(40),
                        Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'sub_admin' => 'Sub-admin',
                                'editor' => 'Editor',
                                'author' => 'Author',
                                'user' => 'User',
                            ])
                            ->required()
                            ->default('author')
                            // Block self-demotion / self-lockout. An admin
                            // editing their own row cannot change their
                            // role here; promoting/demoting yourself is
                            // an out-of-band action via the make:admin CLI.
                            ->disabled(fn ($record) => $record && auth()->id() === $record->id)
                            ->helperText(fn ($record) => $record && auth()->id() === $record->id
                                ? 'You cannot change your own role from this form.'
                                : null),
                        Select::make('status')
                            ->options([1 => 'Active', 0 => 'Banned'])
                            ->default(1)
                            ->required()
                            // Same reasoning: don't let an admin ban
                            // their own account and lock themselves out.
                            ->disabled(fn ($record) => $record && auth()->id() === $record->id)
                            ->helperText(fn ($record) => $record && auth()->id() === $record->id
                                ? 'You cannot change your own status from this form.'
                                : null),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(12)
                            ->helperText('Leave blank to keep the current password.')
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Facades\Hash::make($state))
                            ->required(fn (string $operation) => $operation === 'create'),
                    ]),
                ]),

                Tab::make('Public profile')->schema([
                    Section::make()
                        ->description('Shown on /author/{slug} when this user has bylined or co-bylined an article.')
                        ->schema([
                            TextInput::make('slug')
                                ->label('Profile slug')
                                ->maxLength(100)
                                ->helperText('Auto-generated from the name on save if blank. Used in /author/{slug}.'),
                            Textarea::make('bio')
                                ->label('Bio')
                                ->rows(4)
                                ->maxLength(1000)
                                ->helperText('A few sentences about the author. Shown on the author profile page and the byline strip on each of their articles.'),
                            TextInput::make('twitter_handle')
                                ->label('Twitter / X handle')
                                ->maxLength(60)
                                ->prefix('@')
                                ->helperText('Without the @ — e.g., "kmyers".'),
                            FileUpload::make('image')
                                ->label('Profile photo')
                                ->image()
                                ->disk('public')
                                ->directory('user_photos')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                                ->maxSize(2048),
                        ]),
                ]),

                Tab::make('AI access')->schema([
                    Section::make()
                        ->description('Optional per-editor caps. Both blank = inherits provider-level limits only. Useful for sandboxing junior editors or limiting a single seat\'s share of the org budget.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('ai_daily_budget_usd')
                                ->label('Daily AI budget (USD)')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->placeholder('e.g. 1.00')
                                ->helperText('Resets at midnight server time.')
                                ->afterStateHydrated(function ($component, $state, $record) {
                                    if ($record && $record->ai_daily_budget_microusd) {
                                        $component->state(round($record->ai_daily_budget_microusd / 1_000_000, 2));
                                    }
                                })
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $set('ai_daily_budget_microusd', $state === null || $state === ''
                                        ? null
                                        : (int) round(((float) $state) * 1_000_000));
                                }),

                            TextInput::make('ai_calls_per_minute')
                                ->label('Rate limit (calls/min)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(120)
                                ->placeholder('e.g. 30')
                                ->helperText('Sliding 60-second window. Blank = no limit.'),

                            \Filament\Forms\Components\Hidden::make('ai_daily_budget_microusd'),
                        ]),
                ]),
            ]),
        ]);
    }
}
