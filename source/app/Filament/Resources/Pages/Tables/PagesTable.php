<?php

namespace App\Filament\Resources\Pages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('page_title')->label('Title')->searchable()->sortable(),
                TextColumn::make('page_slug')->label('Slug')->searchable(),
                TextColumn::make('page_order')->label('Order')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Published' : 'Draft'),
            ])
            ->defaultSort('page_order')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
