<?php

namespace App\Filament\Resources\PodcastShows\RelationManagers;

use App\Models\PodcastEpisode;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EpisodesRelationManager extends RelationManager
{
    protected static string $relationship = 'episodes';

    protected static ?string $title = 'Episodes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Episode')->columns(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                        if (! $record && empty($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')->required()->maxLength(255),
                Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
            ]),
            Section::make('Audio')->columns(3)->schema([
                FileUpload::make('media_url')
                    ->label('Audio file')
                    ->disk('public')
                    ->directory('podcasts/episodes')
                    ->acceptedFileTypes(['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav'])
                    // 80 MB ceiling: enough for a ~80-min MP3 at 128 kbps
                    // or a 40-min one at 256 kbps. The previous 300 MB
                    // cap made disk-exhaustion DoS too easy on shared
                    // hosting. Producers needing larger files can host
                    // them externally and paste the URL.
                    ->maxSize(81920)
                    ->columnSpanFull()
                    ->helperText('MP3, M4A, or WAV. Max 80 MB.'),
                TextInput::make('duration_seconds')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Total seconds. Required by Apple Podcasts.'),
                TextInput::make('media_size_bytes')
                    ->numeric()
                    ->label('File size (bytes)')
                    ->helperText('Enclosure length for RSS. Auto-filled if left blank.'),
                Select::make('media_mime')
                    ->options([
                        'audio/mpeg'   => 'audio/mpeg (MP3)',
                        'audio/mp4'    => 'audio/mp4 (M4A)',
                        'audio/x-m4a'  => 'audio/x-m4a',
                        'audio/wav'    => 'audio/wav',
                    ])
                    ->default('audio/mpeg')
                    ->required(),
            ]),
            Section::make('Metadata')->columns(3)->schema([
                TextInput::make('season_number')->numeric()->minValue(1),
                TextInput::make('episode_number')->numeric()->minValue(1),
                Select::make('episode_type')
                    ->options(PodcastEpisode::TYPES)
                    ->default('full')
                    ->required(),
                Toggle::make('explicit')->inline(false)->helperText('Overrides the show-level explicit flag.'),
                DateTimePicker::make('published_at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Future dates schedule the episode; past dates publish immediately.'),
            ]),
            Section::make('Show notes')->schema([
                RichEditor::make('show_notes')
                    ->label(false)
                    ->columnSpanFull()
                    ->helperText('Appears on the episode page and in the <content:encoded> field of the RSS.'),
            ])->collapsible()->collapsed(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('episode_number')->label('#')->sortable()->toggleable(),
                TextColumn::make('title')->searchable()->limit(50),
                TextColumn::make('published_at')->dateTime('M j, Y')->sortable()->placeholder('—'),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state, $record) => $record?->durationFormatted() ?? '—')
                    ->toggleable(),
                TextColumn::make('episode_type')->badge()->toggleable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Add episode')
                    ->mutateDataUsing(fn (array $data) => $this->normalizeMedia($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => $this->normalizeMedia($data)),
                DeleteAction::make(),
            ]);
    }

    /**
     * Filament stores FileUpload state as the disk-relative path. For the
     * podcast feed we need a fully-qualified URL so Apple / Spotify can
     * fetch the audio. Convert here once on save.
     *
     * Also auto-fill media_size_bytes from the uploaded file if the editor
     * left it blank — Apple requires the enclosure `length` attribute.
     */
    protected function normalizeMedia(array $data): array
    {
        if (! empty($data['media_url']) && ! str_starts_with($data['media_url'], 'http')) {
            $path = $data['media_url'];
            $disk = Storage::disk('public');
            if (empty($data['media_size_bytes']) && $disk->exists($path)) {
                $data['media_size_bytes'] = $disk->size($path);
            }
            $data['media_url'] = $disk->url($path);
        }
        return $data;
    }
}
