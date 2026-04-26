<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\News\NewsResource;
use App\Models\News;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Most-recently-edited articles, with a row-action to open them in the
 * NewsResource. Saves a click vs going to the full table.
 */
class RecentArticles extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent articles')
            ->query(News::query()->with(['user', 'category'])->latest('updated_at')->limit(8))
            ->columns([
                TextColumn::make('title')->limit(60)->wrap(),
                TextColumn::make('category.name')->label('Section')->toggleable(),
                TextColumn::make('user.name')->label('Author')->toggleable(),
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
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('updated_at')->dateTime('M j · g:i a')->sortable()->label('Edited'),
            ])
            ->paginated(false)
            ->recordUrl(fn (News $record): string => NewsResource::getUrl('edit', ['record' => $record]));
    }
}
