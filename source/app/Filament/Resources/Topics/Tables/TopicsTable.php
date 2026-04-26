<?php

namespace App\Filament\Resources\Topics\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->toggleable()->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('manual_articles_count')
                    ->label('Pinned')
                    ->counts('manualArticles')
                    ->toggleable(),
                TextColumn::make('sort')->label('Order')->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('M j, Y g:ia')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('sort')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
