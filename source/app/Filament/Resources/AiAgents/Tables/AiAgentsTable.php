<?php

namespace App\Filament\Resources\AiAgents\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiAgentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('tool_keys')
                    ->label('Tools')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('schedule_frequency')
                    ->label('Schedule')
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? \App\Models\AiAgent::FREQUENCIES[$state].(in_array($state, ['daily', 'weekly'], true) && $record->schedule_hour_utc !== null
                            ? sprintf(' @ %02d:00 UTC', $record->schedule_hour_utc)
                            : '')
                        : '—')
                    ->toggleable(),
                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('never')
                    ->toggleable(),
                TextColumn::make('runs_count')->label('Runs')->counts('runs')->toggleable(),
                TextColumn::make('updatedBy.name')->label('Last edited by')->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->dateTime('M j, Y g:ia')->sortable()->toggleable(),
            ])
            ->defaultSort('key')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
