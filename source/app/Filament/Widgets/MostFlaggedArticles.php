<?php

namespace App\Filament\Widgets;

use App\Models\ArticleLintResult;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Surfaces articles the AI lint flagged with critical findings, ordered
 * by criticals desc. Hidden when no rows have any criticals so the
 * dashboard stays calm on a clean newsroom.
 *
 * Drills into the article edit page on row click; editors can re-run
 * lint there from the AI assist menu.
 */
class MostFlaggedArticles extends BaseWidget implements HasTable
{
    use InteractsWithTable;

    protected static ?int $sort = 3;

    protected static ?string $heading = 'Most flagged articles (AI lint)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ArticleLintResult::where('critical_count', '>', 0)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ArticleLintResult::query()
                ->where('critical_count', '>', 0)
                ->with('news')
                ->orderByDesc('critical_count')
                ->orderByDesc('warn_count')
                ->limit(10))
            ->columns([
                TextColumn::make('news.title')
                    ->label('Article')
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('critical_count')
                    ->label('Critical')
                    ->badge()
                    ->color('danger')
                    ->alignRight(),
                TextColumn::make('warn_count')
                    ->label('Warn')
                    ->badge()
                    ->color('warning')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('ran_at')
                    ->label('Last lint')
                    ->since()
                    ->toggleable(),
            ])
            ->recordUrl(fn ($record) => $record->news_id
                ? \App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $record->news_id])
                : null);
    }
}
