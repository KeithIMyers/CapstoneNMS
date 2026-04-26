<?php

namespace App\Filament\Resources\AiRequests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j · g:i:s a')
                    ->sortable(),
                TextColumn::make('provider.name')->label('Provider')->toggleable(),
                TextColumn::make('model')->toggleable(),
                TextColumn::make('purpose')
                    ->badge()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('user.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'ok' ? 'success' : 'danger'),
                TextColumn::make('tokens_in')->label('In')->numeric()->alignRight()->toggleable(),
                TextColumn::make('tokens_out')->label('Out')->numeric()->alignRight()->toggleable(),
                TextColumn::make('duration_ms')
                    ->label('Time')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state).' ms' : '—')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('cost_microusd')
                    ->label('Cost (USD)')
                    ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format($state / 1_000_000, 4) : '—')
                    ->alignRight()
                    ->sortable(),
                TextColumn::make('error_message')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['ok' => 'OK', 'error' => 'Error']),
                SelectFilter::make('purpose')
                    ->options(fn () => \App\Models\AiRequest::query()
                        ->whereNotNull('purpose')
                        ->distinct()
                        ->pluck('purpose', 'purpose')
                        ->toArray()),
            ])
            ->defaultSort('created_at', 'desc')
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
