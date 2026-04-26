<?php

namespace App\Filament\Resources\PodcastShows\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PodcastShowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->toggleable()->searchable(),
                TextColumn::make('author')->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('episodes_count')->label('Episodes')->counts('episodes')->toggleable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('M j, Y')->toggleable(),
            ])
            ->defaultSort('title')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
