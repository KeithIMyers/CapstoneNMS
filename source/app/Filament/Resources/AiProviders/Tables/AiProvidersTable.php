<?php

namespace App\Filament\Resources\AiProviders\Tables;

use App\Models\AiProvider;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AiProvider::KINDS[$state] ?? $state),
                TextColumn::make('default_model')->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('today_spend')
                    ->label('Today')
                    ->state(function ($record) {
                        $cap = $record->daily_budget_microusd;
                        $spent = $record->spentTodayMicroUsd();
                        if (! $cap) {
                            return $spent ? '$'.number_format($spent / 1_000_000, 2) : '—';
                        }
                        return sprintf(
                            '$%s / $%s',
                            number_format($spent / 1_000_000, 2),
                            number_format($cap / 1_000_000, 2),
                        );
                    })
                    ->color(function ($record) {
                        if (! $record->daily_budget_microusd) return 'gray';
                        $pct = $record->spentTodayMicroUsd() / max(1, $record->daily_budget_microusd);
                        return match (true) {
                            $pct >= 1.0 => 'danger',
                            $pct >= 0.8 => 'warning',
                            default     => 'success',
                        };
                    })
                    ->badge(fn ($record) => (bool) $record->daily_budget_microusd),
                TextColumn::make('requests_count')
                    ->label('Requests (all time)')
                    ->counts('requests')
                    ->toggleable(),
                TextColumn::make('updated_at')->dateTime('M j, Y g:ia')->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
