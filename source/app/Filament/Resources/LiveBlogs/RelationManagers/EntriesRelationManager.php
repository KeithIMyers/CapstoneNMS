<?php

namespace App\Filament\Resources\LiveBlogs\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Entries';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('headline')
                ->maxLength(255)
                ->helperText('Optional bold lede on the entry. Leave blank for a plain timestamped post.'),
            RichEditor::make('body')
                ->required()
                ->columnSpanFull(),
            Toggle::make('is_pinned')
                ->label('Pin this entry to the top'),
            DateTimePicker::make('posted_at')
                ->seconds(false)
                ->native(false)
                ->helperText('Auto-set to "now" if blank.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('headline')
            ->columns([
                IconColumn::make('is_pinned')->label('📌')->boolean()->toggleable(),
                TextColumn::make('headline')->limit(50)->placeholder('—'),
                TextColumn::make('body')
                    ->limit(80)
                    ->wrap()
                    ->formatStateUsing(fn ($state) => strip_tags($state)),
                TextColumn::make('postedBy.name')->label('Posted by')->toggleable(),
                TextColumn::make('posted_at')->dateTime('M j · g:i a')->sortable(),
            ])
            ->defaultSort('posted_at', 'desc')
            ->headerActions([
                CreateAction::make()->label('Add entry'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
