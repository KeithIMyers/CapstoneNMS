<?php

namespace App\Filament\Resources\NewsAgents\Schemas;

use App\Models\AiProvider;
use App\Models\NewsAgent;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * NewsAgent edit form. Five tabs:
 *
 *   Persona          name + email + photo + bio (mirrored to the
 *                    underlying User row so the public byline picks
 *                    up everything the existing AuthorController
 *                    renders).
 *   Voice + KB       writing style prose + markdown knowledgebase.
 *   Coverage         topic keywords (used by the dispatcher to pick
 *                    queued stories) + per-run config.
 *   Models           per-agent overrides for chat + image providers.
 *   Schedule         frequency + UTC hour + posts-per-run cap.
 */
class NewsAgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Persona')->schema(self::personaTab()),
                Tab::make('Voice & knowledgebase')->schema(self::voiceTab()),
                Tab::make('Coverage')->schema(self::coverageTab()),
                Tab::make('Models')->schema(self::modelsTab()),
                Tab::make('Schedule')->schema(self::scheduleTab()),
            ]),
        ]);
    }

    private static function personaTab(): array
    {
        return [
            Section::make()->columns(2)->schema([
                Select::make('user_id')
                    ->label('Persona user row')
                    ->options(fn () => User::query()
                        ->where('is_agent', true)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('email')->email()->required()->unique('users', 'email'),
                        Textarea::make('bio')->rows(3)->maxLength(2000),
                        FileUpload::make('image')
                            ->label('Profile photo')
                            ->image()
                            ->disk('public')
                            ->directory('user_photos')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']),
                    ])
                    ->createOptionUsing(function (array $data) {
                        // forceCreate: `role`, `status`, `is_agent` are
                        // outside the User model's fillable allowlist
                        // and must be set from a trusted boundary like
                        // this admin-only form.
                        $u = User::forceCreate([
                            'name'     => $data['name'],
                            'email'    => $data['email'],
                            'password' => bcrypt(\Illuminate\Support\Str::random(40)),
                            'role'     => 'author',
                            'status'   => 1,
                            'bio'      => $data['bio'] ?? null,
                            'image'    => $data['image'] ?? null,
                            'is_agent' => true,
                        ]);
                        return $u->id;
                    })
                    ->helperText('Pick or create the User row that will be the byline. Only users flagged as agents are shown.'),

                Toggle::make('is_active')->default(true)->inline(false),
            ]),
        ];
    }

    private static function voiceTab(): array
    {
        return [
            Section::make('Writing style')
                ->description('Free-form prose describing how this agent writes — voice, tone, beats, signature moves. The dispatcher prepends this to the system prompt on every run.')
                ->schema([
                    Textarea::make('writing_style')
                        ->label(false)
                        ->rows(8)
                        ->maxLength(8000)
                        ->columnSpanFull()
                        ->placeholder("Example: A veteran sportswriter with a dry, AP-style voice. Leads with the score and the key play. Drops the occasional historical comparison. Avoids hyperbole. Always names the head coach by full name on first reference."),
                ]),

            Section::make('Knowledgebase (markdown)')
                ->description('Baseline knowledge the agent should treat as a given. Useful for league-specific facts, beat history, named-source rules, internal style guide. The dispatcher injects this verbatim into the system prompt.')
                ->schema([
                    Textarea::make('knowledgebase')
                        ->label(false)
                        ->rows(14)
                        ->maxLength(60000)
                        ->columnSpanFull()
                        ->placeholder("# Beat notes\n\n- Always check the official MLB scoring rules before describing a contested play.\n- Pacific Coast League stadium names that changed in 2026: …\n\n# Named sources\n\n- John Doe is the team's beat reporter; cite as 'longtime beat writer John Doe' on first reference."),
                ]),
        ];
    }

    private static function coverageTab(): array
    {
        return [
            Section::make()->columns(2)->schema([
                TagsInput::make('topic_keywords')
                    ->label('Topic keywords')
                    ->columnSpanFull()
                    ->helperText('Used to filter the story queue. The dispatcher picks queued stories whose topic / source URLs match any of these keywords. Empty = the agent processes any unassigned story.'),

                TextInput::make('target_word_count')
                    ->label('Target word count')
                    ->numeric()
                    ->default(600)
                    ->minValue(150)
                    ->maxValue(3000),

                TextInput::make('posts_per_run')
                    ->label('Max posts per run')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(20)
                    ->helperText('How many queued stories the dispatcher processes for this agent each tick.'),
            ]),
        ];
    }

    private static function modelsTab(): array
    {
        return [
            Section::make('Chat model (research + drafting)')
                ->description('Override the default provider / model for this agent. Leave blank to use the install-wide default.')
                ->columns(3)
                ->schema([
                    Select::make('ai_provider_id')
                        ->label('Provider')
                        ->options(fn () => AiProvider::active()
                            ->whereIn('kind', [AiProvider::KIND_OPENAI, AiProvider::KIND_ANTHROPIC, AiProvider::KIND_OLLAMA])
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->placeholder('— use default —')
                        ->searchable(),
                    TextInput::make('ai_model')
                        ->label('Model')
                        ->maxLength(120)
                        ->placeholder('e.g. claude-3-5-sonnet-latest, gpt-4o-mini, llama3.1'),
                    TextInput::make('temperature')
                        ->numeric()
                        ->step(0.05)
                        ->minValue(0)
                        ->maxValue(2)
                        ->default(0.5),
                    TextInput::make('max_tokens')
                        ->label('Max tokens / step')
                        ->numeric()
                        ->minValue(256)
                        ->maxValue(8192)
                        ->default(3000)
                        ->columnSpan(2),
                ]),

            Section::make('Image generation')
                ->description('Override the image-gen provider for this agent. NanoBanana = Google Gemini Flash Image. Leave blank to use the install-wide default image provider.')
                ->columns(3)
                ->schema([
                    Select::make('image_provider_id')
                        ->label('Image provider')
                        ->options(fn () => AiProvider::active()
                            ->whereIn('kind', [AiProvider::KIND_OPENAI, AiProvider::KIND_GOOGLE_IMAGEN])
                            ->whereNotNull('image_model')
                            ->where('image_model', '!=', '')
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->placeholder('— use default —')
                        ->searchable(),
                    TextInput::make('image_model')
                        ->label('Image model override')
                        ->maxLength(120)
                        ->placeholder('e.g. gpt-image-1, gemini-2.5-flash-image-preview')
                        ->columnSpan(2),
                    Textarea::make('image_style_hint')
                        ->label('Image style hint')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->placeholder('Photorealistic newsroom photo, natural lighting, journalistic, no text in image.')
                        ->helperText('Appended to every image prompt this agent generates so all hero images share a consistent visual voice.'),
                ]),
        ];
    }

    private static function scheduleTab(): array
    {
        return [
            Section::make()
                ->description('Schedule controls when the dispatcher runs this agent. Manual-only agents leave Frequency blank — they only fire when an editor clicks "Run now" from the agent\'s edit page.')
                ->columns(2)
                ->schema([
                    Select::make('schedule_frequency')
                        ->label('Frequency')
                        ->options(NewsAgent::FREQUENCIES)
                        ->placeholder('— manual only —')
                        ->live(),
                    TextInput::make('schedule_hour_utc')
                        ->label('Hour (UTC)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(23)
                        ->default(13) // ~6am PT
                        ->visible(fn ($get) => in_array($get('schedule_frequency'), [NewsAgent::FREQ_DAILY, NewsAgent::FREQ_WEEKLY], true))
                        ->helperText('Hour the daily / weekly run fires (0–23 UTC).'),
                ]),
        ];
    }
}
