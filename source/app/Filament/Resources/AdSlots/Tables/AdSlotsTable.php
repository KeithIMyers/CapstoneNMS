<?php

namespace App\Filament\Resources\AdSlots\Tables;

use App\Models\AdSlot;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdSlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('placement')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AdSlot::PLACEMENTS[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        AdSlot::KIND_BANNER => 'Banner',
                        AdSlot::KIND_HTML   => 'HTML / JS',
                        default             => $state,
                    })
                    ->color(fn ($state) => $state === AdSlot::KIND_BANNER ? 'success' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('weight')->sortable()->toggleable(),
                TextColumn::make('start_at')->dateTime('M j, Y g:ia')->toggleable()->placeholder('—'),
                TextColumn::make('end_at')->dateTime('M j, Y g:ia')->toggleable()->placeholder('—'),
                TextColumn::make('impressions')->numeric()->sortable()->toggleable(),
                TextColumn::make('clicks')->numeric()->sortable()->toggleable(),
                TextColumn::make('ctr')
                    ->label('CTR')
                    ->state(function ($record) {
                        if (! $record->impressions) return '—';
                        return number_format($record->clicks / $record->impressions * 100, 2).'%';
                    })
                    ->toggleable(),
            ])
            ->defaultSort('placement')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
