<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')
                ->label('Parent category')
                ->options(function ($record) {
                    $excludeIds = $record ? [$record->id, ...$record->descendantIds()] : [];
                    return Category::query()
                        ->orderBy('name')
                        ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
                        ->pluck('name', 'id');
                })
                ->searchable()
                ->placeholder('— top-level —')
                ->helperText('Use sections (e.g., "Politics") as parents and sub-sections (e.g., "Congress") as children.'),
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                    if (! $record && empty($get('slug'))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(120),
            Textarea::make('description')
                ->rows(2)
                ->maxLength(500)
                ->helperText('Shown on the category landing page; useful for SEO.'),
            TextInput::make('cat_order')
                ->label('Sort order')
                ->numeric()
                ->default(0),
            Select::make('status')
                ->options([1 => 'Active', 0 => 'Hidden'])
                ->default(1)
                ->required(),
        ]);
    }
}
