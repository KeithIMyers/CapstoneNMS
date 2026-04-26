<?php

namespace App\Filament\Resources\NewsAgents\Tables;

use App\Models\NewsAgent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NewsAgentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('user.image')
                    ->label('Photo')
                    ->circular()
                    ->disk('public')
                    ->size(40),
                TextColumn::make('user.name')->label('Name')->searchable()->sortable(),
                TextColumn::make('topic_keywords')
                    ->label('Topics')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('schedule_frequency')
                    ->label('Schedule')
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? NewsAgent::FREQUENCIES[$state].(in_array($state, ['daily','weekly'], true) && $record->schedule_hour_utc !== null
                            ? sprintf(' @ %02d:00 UTC', $record->schedule_hour_utc)
                            : '')
                        : 'manual only')
                    ->toggleable(),
                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('never')
                    ->toggleable(),
                TextColumn::make('stories_count')
                    ->label('Queue')
                    ->counts(['stories' => fn ($q) => $q->where('status', 'queued')])
                    ->toggleable(),
            ])
            ->defaultSort('user_id')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
