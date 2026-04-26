<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Models\Comments;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('content')->limit(80)->wrap()->searchable(),
                TextColumn::make('author_display')
                    ->label('Author')
                    ->state(fn ($record) => $record->authorDisplayName())
                    ->description(fn ($record) => $record->isGuest()
                        ? 'Guest · '.($record->guest_email ?: 'no email')
                        : null)
                    ->toggleable()
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                              ->orWhere('guest_name', 'like', "%{$search}%")
                              ->orWhere('guest_email', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('post.title')->label('Article')->limit(40)->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        Comments::STATUS_APPROVED => 'success',
                        Comments::STATUS_PENDING => 'warning',
                        Comments::STATUS_SPAM => 'danger',
                        Comments::STATUS_TRASH => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('ai_verdict')
                    ->label('AI')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        Comments::VERDICT_ALLOW  => 'success',
                        Comments::VERDICT_REVIEW => 'warning',
                        Comments::VERDICT_REJECT => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state ? Comments::VERDICTS[$state] ?? $state : '—')
                    ->tooltip(fn ($record) => $record?->ai_reason)
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Comments::STATUS_PENDING => 'Pending',
                    Comments::STATUS_APPROVED => 'Approved',
                    Comments::STATUS_SPAM => 'Spam',
                    Comments::STATUS_TRASH => 'Trash',
                ])->default(Comments::STATUS_PENDING),
                SelectFilter::make('ai_verdict')
                    ->label('AI verdict')
                    ->options(Comments::VERDICTS),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== Comments::STATUS_APPROVED)
                    ->action(fn ($record) => $record->update(['status' => Comments::STATUS_APPROVED])),
                Action::make('spam')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status !== Comments::STATUS_SPAM)
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['status' => Comments::STATUS_SPAM])),
                Action::make('reclassify')
                    ->label('AI reclassify')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->action(function ($record) {
                        try {
                            app(\App\Services\Ai\CommentModerator::class)->classify($record);
                            \Filament\Notifications\Notification::make()
                                ->title('Reclassified')
                                ->body($record->fresh()->ai_verdict
                                    ? 'Verdict: '.strtoupper($record->fresh()->ai_verdict)
                                    : 'No verdict produced.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Reclassify failed')
                                ->body(\Illuminate\Support\Str::limit($e->getMessage(), 300))
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['status' => Comments::STATUS_APPROVED])),
                    BulkAction::make('mark_spam_selected')
                        ->label('Mark as spam')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => Comments::STATUS_SPAM])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
