<?php

namespace App\Filament\Resources\AgentRuns\Tables;

use App\Models\AgentRun;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')
                    ->label('When')
                    ->dateTime('M j · g:i:s a')
                    ->sortable(),
                TextColumn::make('agent_key')->badge()->searchable()->sortable(),
                TextColumn::make('user.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        AgentRun::STATUS_DONE    => 'success',
                        AgentRun::STATUS_ERROR   => 'danger',
                        AgentRun::STATUS_RUNNING => 'warning',
                        default                  => 'gray',
                    }),
                TextColumn::make('iterations')->label('Steps')->numeric()->alignRight()->toggleable(),
                TextColumn::make('tokens_in_total')->label('In')->numeric()->alignRight()->toggleable(),
                TextColumn::make('tokens_out_total')->label('Out')->numeric()->alignRight()->toggleable(),
                TextColumn::make('duration_ms')
                    ->label('Time')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state).' ms' : '—')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('cost_microusd')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state) => $state ? '$'.number_format($state / 1_000_000, 4) : '—')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('input_message')
                    ->label('Input')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    AgentRun::STATUS_DONE => 'Done',
                    AgentRun::STATUS_ERROR => 'Error',
                    AgentRun::STATUS_RUNNING => 'Running',
                ]),
                SelectFilter::make('agent_key')
                    ->label('Agent')
                    ->options(fn () => AgentRun::query()
                        ->distinct()
                        ->pluck('agent_key', 'agent_key')
                        ->toArray()),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
