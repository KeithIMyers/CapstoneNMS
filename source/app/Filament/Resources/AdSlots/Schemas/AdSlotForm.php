<?php

namespace App\Filament\Resources\AdSlots\Schemas;

use App\Models\AdSlot;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdSlotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(150)
                    ->helperText('Internal label only — not shown to visitors.'),
                Select::make('placement')
                    ->options(AdSlot::PLACEMENTS)
                    ->required()
                    ->searchable(),

                Select::make('kind')
                    ->label('Slot type')
                    ->options(AdSlot::KINDS)
                    ->default(AdSlot::KIND_BANNER)
                    ->required()
                    ->live()
                    ->columnSpanFull()
                    ->helperText('"Image banner" is for direct-sold campaigns (image + click URL). "HTML / JS snippet" is for ad-network code (AdSense, header bidding, custom <script>).'),

                Toggle::make('is_active')->default(true)->inline(false),
                TextInput::make('weight')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(1000)
                    ->helperText('Relative weight vs. other active slots in the same placement.'),
                DateTimePicker::make('start_at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Optional. Leave blank to start immediately.'),
                DateTimePicker::make('end_at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Optional. Leave blank for no end.'),
            ]),

            Section::make('Banner image')
                ->description('Upload a creative image and pick a click URL. Click-through is auto-tracked.')
                ->visible(fn ($get) => $get('kind') === AdSlot::KIND_BANNER)
                ->schema([
                    FileUpload::make('image_path')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('ads')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                        ->maxSize(2048)
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('click_url')
                        ->label('Click URL')
                        ->url()
                        ->required()
                        ->placeholder('https://example.com/landing-page')
                        ->columnSpanFull(),
                    TextInput::make('alt_text')
                        ->label('Alt text')
                        ->maxLength(255)
                        ->required()
                        ->placeholder('Acme Co. — try our new product')
                        ->columnSpanFull()
                        ->helperText('Required for accessibility. Shown by screen readers and when the image fails to load.'),
                ]),

            Section::make('HTML / JS snippet')
                ->description('Paste the embed / script code provided by your ad network. Both HTML and <script> tags are honored — the loader re-executes scripts on insertion.')
                ->visible(fn ($get) => $get('kind') === AdSlot::KIND_HTML)
                ->schema([
                    Textarea::make('code')
                        ->rows(10)
                        ->maxLength(20000)
                        ->columnSpanFull()
                        ->placeholder("<script async src=\"https://pagead2.googlesyndication.com/...\"></script>\n<ins class=\"adsbygoogle\" ...></ins>"),
                ]),
        ]);
    }
}
