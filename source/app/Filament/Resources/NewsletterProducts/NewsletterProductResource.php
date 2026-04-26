<?php

namespace App\Filament\Resources\NewsletterProducts;

use App\Filament\Resources\NewsletterProducts\Pages\CreateNewsletterProduct;
use App\Filament\Resources\NewsletterProducts\Pages\EditNewsletterProduct;
use App\Filament\Resources\NewsletterProducts\Pages\ListNewsletterProducts;
use App\Models\NewsletterProduct;
use BackedEnum;
use Filament\Forms\Components\Select;
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

class NewsletterProductResource extends Resource
{
    protected static ?string $model = NewsletterProduct::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;
    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';
    protected static ?string $navigationLabel = 'Products';
    protected static ?string $modelLabel = 'Newsletter product';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($r): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($r): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('slug')->required()->maxLength(80)
                    ->helperText('Used as the form value when readers opt in. e.g. "daily", "weekly", "breaking".'),
                Textarea::make('description')->rows(3)->columnSpanFull(),
                Select::make('cadence')
                    ->options([
                        'daily'      => 'Daily',
                        'weekly'     => 'Weekly',
                        'on-publish' => 'On publish (breaking)',
                        'topical'    => 'Topical / per-tag',
                    ])
                    ->placeholder('— how often —'),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_default')
                    ->helperText('At most one product should be the default. The legacy footer signup, daily-brief sender, and any "subscribe" link without an explicit product slug all target this one.'),
                Toggle::make('active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray'),
                TextColumn::make('cadence')->badge()->toggleable(),
                IconColumn::make('is_default')->boolean()->label('Default')->toggleable(),
                IconColumn::make('active')->boolean()->toggleable(),
                TextColumn::make('subscriptions_count')
                    ->counts(['subscriptions as subs' => fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('unsubscribed_at')])
                    ->label('Active subs')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('sort_order')->sortable()->toggleable(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListNewsletterProducts::route('/'),
            'create' => CreateNewsletterProduct::route('/create'),
            'edit'   => EditNewsletterProduct::route('/{record}/edit'),
        ];
    }
}
