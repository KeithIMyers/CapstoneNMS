<?php

namespace App\Filament\Resources\NewsletterIssues\Pages;

use App\Filament\Resources\NewsletterIssues\NewsletterIssueResource;
use App\Models\NewsletterSend;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ViewNewsletterIssue extends ViewRecord
{
    protected static string $resource = NewsletterIssueResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Issue')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('subject')->columnSpanFull(),
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('sender_email')->label('From'),
                    TextEntry::make('sent_at')->dateTime('M j, Y g:ia'),
                    TextEntry::make('sent_count')->label('Sent')->numeric(),
                    TextEntry::make('failed_count')->label('Failed')->numeric(),
                ]),
            ]),

            Section::make('Engagement')->schema([
                Grid::make(4)->schema([
                    TextEntry::make('opens_total')
                        ->label('Total opens')
                        ->state(fn ($record) => (int) $record->sends()->sum('opens_count')),
                    TextEntry::make('unique_opens')
                        ->label('Unique opens')
                        ->state(fn ($record) => (int) $record->sends()->whereNotNull('first_opened_at')->count()),
                    TextEntry::make('clicks_total')
                        ->label('Total clicks')
                        ->state(fn ($record) => (int) $record->sends()->sum('clicks_count')),
                    TextEntry::make('open_rate')
                        ->label('Open rate')
                        ->state(function ($record) {
                            $opens = (int) $record->sends()->whereNotNull('first_opened_at')->count();
                            $sent  = max(1, (int) $record->sent_count);
                            return number_format($opens * 100 / $sent, 1).'%';
                        }),
                    TextEntry::make('bounced')
                        ->label('Bounced')
                        ->state(fn ($record) => (int) $record->sends()->whereNotNull('bounced_at')->count()),
                    TextEntry::make('complained')
                        ->label('Spam complaints')
                        ->state(fn ($record) => (int) $record->sends()->whereNotNull('complained_at')->count()),
                    TextEntry::make('unsubscribed')
                        ->label('Unsubscribed')
                        ->state(fn ($record) => (int) $record->sends()->whereNotNull('unsubscribed_at')->count()),
                ]),
            ]),

            Section::make('Body summary')->schema([
                TextEntry::make('body_summary')->columnSpanFull()->prose(),
            ])->collapsible(),
        ]);
    }
}
