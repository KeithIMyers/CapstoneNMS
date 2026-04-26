<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\Subscription;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('source')->toggleable(),
                IconColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->getStateUsing(fn (Subscription $r) => $r->confirmed_at !== null),
                IconColumn::make('unsubscribed_at')
                    ->label('Unsubscribed')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->getStateUsing(fn (Subscription $r) => $r->unsubscribed_at !== null),
                TextColumn::make('created_at')->label('Signed up')->dateTime('M j, Y')->sortable(),
                TextColumn::make('confirmed_at')->dateTime('M j, Y')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('signup_ip')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('active')
                    ->label('Active only')
                    ->query(fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('unsubscribed_at')),
                Filter::make('pending')
                    ->label('Awaiting confirmation')
                    ->query(fn ($q) => $q->whereNull('confirmed_at')),
            ])
            ->recordActions([DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
