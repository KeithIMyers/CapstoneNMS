<?php

namespace App\Filament\Resources\Reports\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('post_id')->label('Article ID')->sortable(),
                TextColumn::make('user_id')->label('Reporter ID')->sortable(),
                TextColumn::make('message')->limit(80)->wrap()->searchable(),
                TextColumn::make('date')->label('Reported')->dateTime()->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
