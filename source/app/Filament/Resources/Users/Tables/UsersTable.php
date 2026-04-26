<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->is_agent ? 'Ghost agent · cannot log in' : null),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('role')
                    ->badge()
                    // News-agent personas display the explicit "Agent" badge
                    // even when role is something like "author", so admins
                    // can distinguish them from real authors at a glance.
                    ->formatStateUsing(fn ($state, $record) => $record->is_agent ? 'agent' : (string) $state)
                    ->color(fn ($state, $record) => $record->is_agent ? 'warning' : null),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Active' : 'Banned'),
                IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean(),
                TextColumn::make('created_at')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'admin' => 'Admin',
                    'sub_admin' => 'Sub-admin',
                    'editor' => 'Editor',
                    'author' => 'Author',
                    'user' => 'User',
                ]),
                SelectFilter::make('status')->options([1 => 'Active', 0 => 'Banned']),
                TernaryFilter::make('is_agent')
                    ->label('Ghost agents')
                    ->placeholder('All users')
                    ->trueLabel('Agents only')
                    ->falseLabel('Hide agents')
                    ->default(false), // by default, hide ghost agents from the real-user table
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
