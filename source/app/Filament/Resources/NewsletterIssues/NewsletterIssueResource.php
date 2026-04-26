<?php

namespace App\Filament\Resources\NewsletterIssues;

use App\Filament\Resources\NewsletterIssues\Pages\ListNewsletterIssues;
use App\Filament\Resources\NewsletterIssues\Pages\ViewNewsletterIssue;
use App\Models\NewsletterIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NewsletterIssueResource extends Resource
{
    protected static ?string $model = NewsletterIssue::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;
    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';
    protected static ?string $navigationLabel = 'Issues';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canView($r): bool  { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool  { return false; }
    public static function canEdit($r): bool  { return false; }
    public static function canDelete($r): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        // The view page renders read-only stats; no edit form.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable()->label('#'),
                TextColumn::make('subject')->searchable()->limit(60)->wrap(),
                TextColumn::make('product.name')->label('Product')->toggleable()->sortable(),
                TextColumn::make('sent_count')->label('Sent')->numeric()->sortable(),
                TextColumn::make('failed_count')->label('Failed')->numeric()->sortable()->toggleable(),
                TextColumn::make('opens')
                    ->label('Opens')
                    ->state(fn (NewsletterIssue $r) => (int) $r->sends()->sum('opens_count')),
                TextColumn::make('clicks')
                    ->label('Clicks')
                    ->state(fn (NewsletterIssue $r) => (int) $r->sends()->sum('clicks_count')),
                TextColumn::make('bounced')
                    ->label('Bounced')
                    ->state(fn (NewsletterIssue $r) => (int) $r->sends()->whereNotNull('bounced_at')->count()),
                TextColumn::make('complained')
                    ->label('Complaints')
                    ->state(fn (NewsletterIssue $r) => (int) $r->sends()->whereNotNull('complained_at')->count())
                    ->toggleable(),
                TextColumn::make('open_rate')
                    ->label('Open %')
                    ->state(function (NewsletterIssue $r) {
                        $opens   = (int) $r->sends()->whereNotNull('first_opened_at')->count();
                        $sent    = max(1, (int) $r->sent_count);
                        return number_format($opens * 100 / $sent, 1).'%';
                    })
                    ->toggleable(),
                TextColumn::make('sent_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletterIssues::route('/'),
            'view'  => ViewNewsletterIssue::route('/{record}'),
        ];
    }
}
