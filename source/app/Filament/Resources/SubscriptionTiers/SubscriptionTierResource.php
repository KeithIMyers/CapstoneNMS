<?php

namespace App\Filament\Resources\SubscriptionTiers;

use App\Filament\Resources\SubscriptionTiers\Pages\CreateSubscriptionTier;
use App\Filament\Resources\SubscriptionTiers\Pages\EditSubscriptionTier;
use App\Filament\Resources\SubscriptionTiers\Pages\ListSubscriptionTiers;
use App\Models\SubscriptionTier;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionTierResource extends Resource
{
    protected static ?string $model = SubscriptionTier::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Subscription tiers';
    protected static ?int $navigationSort = 80;

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canCreate(): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($r): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($r): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('A tier is one offering on the public /subscribe page. The slug doubles as the Cashier subscription "name" so $user->subscribed("' . 'gold' . '") works once you create a "gold" tier.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('slug')->required()->maxLength(60)
                        ->helperText('Stable identifier; once a subscriber is on this slug, do not rename it casually — Cashier rows reference it.'),
                    Textarea::make('description')->rows(2)->columnSpanFull()
                        ->helperText('Shown under the tier name on the public picker.'),
                    TextInput::make('stripe_price_monthly')->label('Stripe Price ID (monthly)')->maxLength(191)
                        ->placeholder('price_...'),
                    TextInput::make('stripe_price_annual')->label('Stripe Price ID (annual)')->maxLength(191)
                        ->placeholder('price_...'),
                    TextInput::make('monthly_price_cents')->numeric()->label('Monthly price (cents)')
                        ->helperText('Display only — Stripe is the source of truth for billing.'),
                    TextInput::make('annual_price_cents')->numeric()->label('Annual price (cents)')
                        ->helperText('Display only.'),
                    TextInput::make('currency')->default('USD')->maxLength(8),
                    TextInput::make('sort_order')->numeric()->default(0),
                    Toggle::make('is_default')
                        ->helperText('Marks this tier as "Most popular" on the picker. Pick at most one.'),
                    Toggle::make('active')->default(true),
                    Toggle::make('is_team')
                        ->label('Team plan (multi-seat)')
                        ->helperText('Buyers pick a seat count at checkout; the seat-invite UI lives at /profile/team. Stripe price should be a per-seat unit price.')
                        ->live(),
                    TextInput::make('min_seats')
                        ->label('Minimum seats')
                        ->numeric()->minValue(1)->maxValue(500)
                        ->default(2)
                        ->visible(fn ($get) => (bool) $get('is_team')),
                    TextInput::make('max_seats')
                        ->label('Maximum seats')
                        ->numeric()->minValue(1)->maxValue(500)
                        ->default(50)
                        ->visible(fn ($get) => (bool) $get('is_team')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray'),
                TextColumn::make('monthly_price_label')
                    ->label('Monthly')
                    ->state(fn (SubscriptionTier $r) => $r->monthlyPriceLabel() ?: '—')
                    ->toggleable(),
                TextColumn::make('annual_price_label')
                    ->label('Annual')
                    ->state(fn (SubscriptionTier $r) => $r->annualPriceLabel() ?: '—')
                    ->toggleable(),
                IconColumn::make('is_default')->boolean()->label('Default')->toggleable(),
                IconColumn::make('active')->boolean()->toggleable(),
                TextColumn::make('sort_order')->sortable()->toggleable(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListSubscriptionTiers::route('/'),
            'create' => CreateSubscriptionTier::route('/create'),
            'edit'   => EditSubscriptionTier::route('/{record}/edit'),
        ];
    }
}
