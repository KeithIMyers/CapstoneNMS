<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PagesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('page_title')
                ->required()
                ->maxLength(200)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                    if (! $record && empty($get('page_slug'))) {
                        $set('page_slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('page_slug')
                ->label('Slug')
                ->required()
                ->maxLength(200),
            RichEditor::make('page_content')
                ->label('Content')
                ->required()
                ->columnSpanFull(),
            TextInput::make('page_order')
                ->label('Sort order')
                ->numeric()
                ->default(0),
            Select::make('status')
                ->options([1 => 'Published', 0 => 'Draft'])
                ->default(1)
                ->required(),
        ]);
    }
}
