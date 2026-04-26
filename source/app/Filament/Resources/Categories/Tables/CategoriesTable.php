<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        $depth = $record->depth();
                        return str_repeat('— ', $depth).$state;
                    }),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('slug')->toggleable()->searchable(),
                TextColumn::make('cat_order')->label('Order')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Active' : 'Hidden'),
                TextColumn::make('posts_count')
                    ->label('Articles')
                    ->counts('posts')
                    ->toggleable(),
            ])
            ->defaultSort('cat_order')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
