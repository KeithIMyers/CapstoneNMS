<?php

namespace App\Filament\Resources\PodcastShows\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PodcastShowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Show')->columns(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(200)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                        if (! $record && empty($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(200)
                    ->helperText('URL: /podcasts/{slug}'),
                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                TextInput::make('author')->maxLength(150),
                TextInput::make('language')->default('en-us')->maxLength(8)->helperText('e.g., en-us, es-mx'),
                Toggle::make('explicit')->inline(false)->helperText('Marks the whole show as explicit.'),
                Toggle::make('is_active')->default(true)->inline(false),
            ]),
            Section::make('Artwork')->schema([
                FileUpload::make('artwork_path')
                    ->label(false)
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('podcasts')
                    ->acceptedFileTypes(['image/jpeg', 'image/png'])
                    ->maxSize(8192)
                    ->helperText('Square, 1400-3000px, JPEG or PNG. Apple Podcasts requires at least 1400×1400.'),
            ]),
            Section::make('iTunes / Apple Podcasts')->columns(2)->schema([
                Select::make('itunes_category')
                    ->options([
                        'News' => 'News',
                        'Business' => 'Business',
                        'Government' => 'Government',
                        'Society & Culture' => 'Society & Culture',
                        'Technology' => 'Technology',
                        'Sports' => 'Sports',
                        'Arts' => 'Arts',
                        'Education' => 'Education',
                        'Health & Fitness' => 'Health & Fitness',
                    ])
                    ->default('News'),
                TextInput::make('itunes_subcategory')->maxLength(100),
                TextInput::make('owner_name')->maxLength(150),
                TextInput::make('owner_email')->email()->maxLength(200)->helperText('Required by Apple Podcasts.'),
            ]),
        ]);
    }
}
