<?php

namespace App\Filament\Resources\EmailSequences\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';
    protected static ?string $title = 'Steps';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sort_order')->numeric()->default(0)->required()
                ->helperText('Lower numbers send first. The first step (lowest sort_order) uses delay_hours from when the run is created.'),
            TextInput::make('delay_hours')
                ->label('Delay before sending (hours)')
                ->numeric()->minValue(0)->default(0)
                ->helperText('Counted from the previous step\'s send time, or from the run\'s started_at for the first step.'),
            TextInput::make('subject')->required()->maxLength(255),
            Textarea::make('body_markdown')
                ->label('Body (markdown)')
                ->required()
                ->rows(10)
                ->helperText('Standard Markdown — links, bold/italic, lists. Rendered through Str::markdown.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('subject')->limit(60)->wrap(),
                TextColumn::make('delay_hours')->label('Delay (h)')->numeric(),
                TextColumn::make('updated_at')->dateTime('M j · g:ia')->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add step')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
