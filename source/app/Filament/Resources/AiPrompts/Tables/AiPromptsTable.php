<?php

namespace App\Filament\Resources\AiPrompts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiPromptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('temperature')->toggleable(),
                TextColumn::make('max_tokens')->toggleable(),
                TextColumn::make('provider.name')->label('Pinned provider')->placeholder('—')->toggleable(),
                TextColumn::make('model_override')->placeholder('—')->toggleable(),
                TextColumn::make('updatedBy.name')->label('Last edited by')->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->dateTime('M j, Y g:ia')->sortable()->toggleable(),
            ])
            ->defaultSort('key')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
