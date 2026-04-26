<?php

namespace App\Filament\Resources\News\Tables;

use App\Models\Category;
use App\Models\News;
use App\Filament\Resources\News\NewsResource;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('user.name')
                    ->label('Author')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('editorial_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        News::STATUS_PUBLISHED => 'success',
                        News::STATUS_SCHEDULED => 'info',
                        News::STATUS_IN_REVIEW => 'warning',
                        News::STATUS_UNPUBLISHED, News::STATUS_ARCHIVED => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => ucwords(str_replace('_', ' ', (string) ($state ?? 'draft')))),

                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('views')
                    ->sortable()
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('published_at')
                    ->label('Publish date')
                    ->dateTime('M j, Y g:i a')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('editorial_status')
                    ->options(collect(News::STATUSES)
                        ->mapWithKeys(fn ($s) => [$s => ucwords(str_replace('_', ' ', $s))])
                        ->toArray()),
                TernaryFilter::make('is_featured')->label('Featured'),
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('moveCategory')
                        ->label('Move to category')
                        ->icon('heroicon-o-folder-arrow-down')
                        ->schema([
                            Select::make('category_id')
                                ->label('Target category')
                                ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function (array $data, \Illuminate\Database\Eloquent\Collection $records) {
                            $count = $records->count();
                            News::query()
                                ->whereIn('id', $records->pluck('id'))
                                ->update(['category_id' => (int) $data['category_id']]);
                            Notification::make()
                                ->title("Moved {$count} article(s) to the selected category")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('changeStatus')
                        ->label('Change status')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->schema([
                            Select::make('editorial_status')
                                ->label('New status')
                                ->options([
                                    News::STATUS_DRAFT       => 'Draft',
                                    News::STATUS_IN_REVIEW   => 'In review',
                                    News::STATUS_SCHEDULED   => 'Scheduled',
                                    News::STATUS_PUBLISHED   => 'Published',
                                    News::STATUS_UNPUBLISHED => 'Unpublished',
                                    News::STATUS_ARCHIVED    => 'Archived',
                                ])
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->action(function (array $data, \Illuminate\Database\Eloquent\Collection $records) {
                            // Walk one-by-one rather than mass-update so the
                            // News model's saving hook still runs (legacy
                            // status-int sync, reading-time recompute).
                            $count = 0;
                            foreach ($records as $row) {
                                $row->editorial_status = $data['editorial_status'];
                                $row->save();
                                $count++;
                            }
                            Notification::make()
                                ->title("Updated status on {$count} article(s)")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('toggleFeatured')
                        ->label('Toggle featured')
                        ->icon('heroicon-o-star')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            foreach ($records as $row) {
                                $row->is_featured = ! $row->is_featured;
                                $row->save();
                            }
                            Notification::make()
                                ->title('Toggled featured on '.$records->count().' article(s)')
                                ->success()
                                ->send();
                        }),

                    // Guarded bulk delete. Filament's stock DeleteBulkAction
                    // skips per-record authorization and would let an
                    // author sweep-delete their own *published* articles
                    // (which NewsResource::canDelete blocks one-at-a-time).
                    // This variant filters the selection through the
                    // Resource's canDelete($record) so the per-row gate
                    // is honored.
                    BulkAction::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $allowed = $records->filter(fn ($r) => NewsResource::canDelete($r));
                            $skipped = $records->count() - $allowed->count();

                            $allowed->each(fn ($r) => $r->delete());

                            $msg = 'Deleted '.$allowed->count().' article'.($allowed->count() === 1 ? '' : 's');
                            if ($skipped > 0) {
                                $msg .= "; skipped {$skipped} you don't have permission to delete";
                            }
                            Notification::make()
                                ->title($msg)
                                ->color($skipped > 0 ? 'warning' : 'success')
                                ->send();
                        }),
                ]),
            ]);
    }
}
