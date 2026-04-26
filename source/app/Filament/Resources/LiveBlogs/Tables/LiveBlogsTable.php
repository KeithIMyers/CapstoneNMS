<?php

namespace App\Filament\Resources\LiveBlogs\Tables;

use App\Models\LiveBlog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LiveBlogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(60)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        LiveBlog::STATUS_ACTIVE => 'success',
                        LiveBlog::STATUS_CLOSED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('entries_count')
                    ->counts('entries')
                    ->label('Entries')
                    ->toggleable(),
                TextColumn::make('started_at')->dateTime('M j · g:i a')->sortable(),
                TextColumn::make('ended_at')->dateTime('M j · g:i a')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    LiveBlog::STATUS_DRAFT => 'Draft',
                    LiveBlog::STATUS_ACTIVE => 'Active',
                    LiveBlog::STATUS_CLOSED => 'Closed',
                ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
