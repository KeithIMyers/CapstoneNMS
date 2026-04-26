<?php

namespace App\Filament\Resources\LiveBlogs\Schemas;

use App\Models\LiveBlog;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LiveBlogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, $set, $get, $record) {
                        if (! $record && empty($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('slug')->required()->maxLength(255),
                Select::make('status')
                    ->options([
                        LiveBlog::STATUS_DRAFT => 'Draft',
                        LiveBlog::STATUS_ACTIVE => 'Active',
                        LiveBlog::STATUS_CLOSED => 'Closed',
                    ])
                    ->default(LiveBlog::STATUS_DRAFT)
                    ->required(),
                Textarea::make('summary')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                FileUpload::make('hero_image')
                    ->image()
                    ->disk('public')
                    ->directory('live-blogs')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                    ->columnSpanFull(),
                DateTimePicker::make('started_at')->seconds(false)->native(false),
                DateTimePicker::make('ended_at')->seconds(false)->native(false)
                    ->helperText('Auto-set when status flips to closed.'),
            ]),
        ]);
    }
}
