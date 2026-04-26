<?php

namespace App\Filament\Resources\Donations;

use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Models\Donation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DonationResource extends Resource
{
    protected static ?string $model = Donation::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Donations';
    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canCreate(): bool  { return false; }
    public static function canEdit($r): bool  { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable()->label('#'),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
                TextColumn::make('amount_label')
                    ->label('Amount')
                    ->state(fn (Donation $r) => '$'.number_format($r->amount_cents / 100, 2))
                    ->sortable(['amount_cents']),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'succeeded' => 'success',
                    'failed'    => 'danger',
                    default     => 'gray',
                }),
                TextColumn::make('display_name')
                    ->label('Donor')
                    ->state(fn (Donation $r) => $r->anonymous
                        ? 'Anonymous'
                        : ($r->name ?: $r->user?->name ?: $r->email))
                    ->toggleable(),
                TextColumn::make('email')->toggleable()->searchable(),
                TextColumn::make('message')->limit(60)->toggleable(),
                IconColumn::make('anonymous')->boolean()->label('Anon')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'succeeded' => 'Succeeded',
                    'pending'   => 'Pending',
                    'failed'    => 'Failed',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDonations::route('/'),
        ];
    }
}
