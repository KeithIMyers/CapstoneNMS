<?php

namespace App\Filament\Resources\News\Schemas;

use App\Models\Category;
use App\Models\News;
use App\Models\Revision;
use App\Models\Tag;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class NewsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Article')->schema(self::articleTab()),
                Tab::make('Media & galleries')->schema(self::mediaTab()),
                Tab::make('Taxonomy & publishing')->schema(self::taxonomyTab()),
                Tab::make('Bylines')->schema(self::bylinesTab()),
                Tab::make('Corrections')->schema(self::correctionsTab()),
                Tab::make('Citations')->schema(self::citationsTab()),
                Tab::make('A/B headlines')->schema(self::headlinesTab()),
                Tab::make('Language')->schema(self::languageTab()),
                Tab::make('SEO')->schema(self::seoTab()),
            ]),
        ]);
    }

    private static function articleTab(): array
    {
        return [
            TextInput::make('kicker')
                ->maxLength(80)
                ->helperText('Optional eyebrow above the headline (e.g., "BREAKING").'),
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                    if (! $record && empty($get('slug'))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('subtitle')
                ->label('Subtitle / dek')
                ->maxLength(500)
                ->helperText('One-line summary shown under the headline.'),
            TextInput::make('dateline')
                ->maxLength(120)
                ->helperText('e.g., "WASHINGTON — " — appears at the start of the body.'),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->helperText('URL-safe identifier. Auto-generated from the title.'),
            Textarea::make('excerpt')
                ->required()
                ->rows(3)
                ->maxLength(1000),
            // Two parallel body editors. Authors pick one and the
            // renderer prefers blocks when set; legacy articles keep
            // working through the plain-HTML field. Neither is
            // required individually — the model-level validator
            // demands at least one of them is non-empty.
            RichEditor::make('content')
                ->label('Body (legacy HTML)')
                ->helperText('Use either this or the block editor below — not both. New articles should prefer blocks.')
                ->columnSpanFull()
                ->rules([
                    function () {
                        return function (string $attribute, $value, \Closure $fail) {
                            // Allow empty content if blocks are populated.
                            $req = request();
                            $blocks = $req->input('data.content_blocks') ?? $req->input('content_blocks') ?? [];
                            if (empty(trim((string) $value)) && empty($blocks)) {
                                $fail('Provide either a body (HTML) or at least one block.');
                            }
                        };
                    },
                ]),

            \Filament\Forms\Components\Builder::make('content_blocks')
                ->label('Body (block editor)')
                ->helperText('Drag blocks into place; render order on the public page matches the order here.')
                ->columnSpanFull()
                ->collapsible()
                ->blockNumbers(false)
                ->blocks([
                    \Filament\Forms\Components\Builder\Block::make('paragraph')
                        ->label('Paragraph')
                        ->icon('heroicon-o-bars-3-bottom-left')
                        ->schema([
                            RichEditor::make('html')->label(false)->required(),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('heading')
                        ->label('Heading')
                        ->icon('heroicon-o-h2')
                        ->schema([
                            \Filament\Forms\Components\Select::make('level')
                                ->options([2 => 'H2', 3 => 'H3', 4 => 'H4'])
                                ->default(2)
                                ->required(),
                            TextInput::make('text')->label('Heading text')->required()->maxLength(200),
                            TextInput::make('anchor')->label('URL anchor (optional)')->maxLength(80)
                                ->helperText('Leave blank to auto-generate from the heading text.'),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('image')
                        ->label('Image')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            FileUpload::make('src')
                                ->label('File')
                                ->image()
                                ->disk('public')
                                ->directory('news/blocks')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                                ->maxSize(4096)
                                ->required(),
                            TextInput::make('alt')->label('Alt text')->required()->maxLength(255),
                            TextInput::make('caption')->maxLength(500),
                            TextInput::make('credit')->maxLength(200),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('quote')
                        ->label('Pull quote')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->schema([
                            \Filament\Forms\Components\Textarea::make('text')->label('Quote')->required()->rows(3),
                            TextInput::make('attribution')->label('Attribution')->maxLength(160)
                                ->helperText('e.g. "Sen. Jane Doe (D-NY) at the press conference".'),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('callout')
                        ->label('Callout')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            \Filament\Forms\Components\Select::make('tone')
                                ->options(['note' => 'Note', 'info' => 'Info', 'tip' => 'Tip', 'warn' => 'Warning'])
                                ->default('note')
                                ->required(),
                            RichEditor::make('html')->label('Body')->required(),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('embed')
                        ->label('Video / embed')
                        ->icon('heroicon-o-tv')
                        ->schema([
                            \Filament\Forms\Components\Textarea::make('html')
                                ->label('Embed HTML')
                                ->required()
                                ->rows(4)
                                ->helperText('Paste the iframe from YouTube / Vimeo / Twitch / Dailymotion / Facebook. Other origins are stripped at render.'),
                            TextInput::make('provider')->maxLength(40)
                                ->helperText('Optional label, e.g. "youtube".'),
                        ]),
                    \Filament\Forms\Components\Builder\Block::make('separator')
                        ->label('Divider')
                        ->icon('heroicon-o-minus')
                        ->schema([]),
                    \Filament\Forms\Components\Builder\Block::make('html')
                        ->label('Raw HTML (advanced)')
                        ->icon('heroicon-o-code-bracket')
                        ->schema([
                            \Filament\Forms\Components\Textarea::make('html')
                                ->required()
                                ->rows(6)
                                ->helperText('Sanitized at render: <script>, event handlers, and javascript:/data: schemes get stripped. Use Embed block for iframes.'),
                        ]),
                ]),
        ];
    }

    private static function mediaTab(): array
    {
        return [
            Section::make('Lead image')->schema([
                FileUpload::make('image')
                    ->label(false)
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('news')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                    ->maxSize(4096)
                    ->helperText('JPEG, PNG, GIF, or WebP. Max 4 MB.'),
                TextInput::make('image_alt')
                    ->label('Alt text')
                    ->maxLength(255)
                    ->helperText('Required for accessibility and SEO.'),
                TextInput::make('image_caption')->label('Caption')->maxLength(500),
                TextInput::make('image_credit')->label('Photo credit')->maxLength(200)
                    ->helperText('e.g., "Reuters / John Doe".'),
            ]),

            Section::make('Gallery')->schema([
                Repeater::make('gallery')
                    ->label(false)
                    ->relationship('gallery')
                    ->schema([
                        FileUpload::make('image')
                            ->image()
                            ->disk('public')
                            ->directory('news')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                            ->maxSize(4096)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->reorderable(false)
                    ->grid(3)
                    ->addActionLabel('Add gallery image')
                    ->columnSpanFull(),
            ])->collapsible()->collapsed(),

            Section::make('Video embed')->schema([
                Textarea::make('video_embed_code')
                    ->label(false)
                    ->rows(3)
                    ->maxLength(4000)
                    ->helperText('Optional iframe embed from YouTube, Vimeo, Twitch, etc.'),
            ])->collapsible()->collapsed(),
        ];
    }

    private static function bylinesTab(): array
    {
        return [
            Section::make()->schema([
                Select::make('user_id')
                    ->label('Primary byline')
                    ->options(fn () => User::query()
                        ->whereIn('role', ['admin', 'sub_admin', 'editor', 'author'])
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('The lead author. Always linked to the public byline.'),

                Select::make('authors')
                    ->label('Co-authors')
                    ->multiple()
                    // `users.role` is qualified because Filament's
                    // relationship() join brings news_authors.role into
                    // scope and an unqualified `role` is ambiguous.
                    ->relationship('authors', 'name', fn ($query) => $query->whereIn('users.role', ['admin', 'sub_admin', 'editor', 'author']))
                    ->searchable()
                    ->preload()
                    ->helperText('Additional bylines beyond the primary author. Role defaults to "contributor"; refine on the Bylines relation manager if needed.')
                    ->columnSpanFull(),
            ]),
        ];
    }

    private static function correctionsTab(): array
    {
        return [
            Section::make()
                ->description('Append a public note when an article is meaningfully updated. Visible at the foot of the article.')
                ->schema([
                    Repeater::make('revisions')
                        ->label(false)
                        ->relationship('revisions')
                        ->schema([
                            Select::make('kind')
                                ->options([
                                    Revision::KIND_UPDATE => 'Update',
                                    Revision::KIND_CORRECTION => 'Correction',
                                    Revision::KIND_CLARIFICATION => 'Clarification',
                                ])
                                ->default(Revision::KIND_UPDATE)
                                ->required(),
                            Textarea::make('note')
                                ->required()
                                ->rows(3)
                                ->maxLength(2000)
                                ->columnSpanFull(),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data) {
                            $data['editor_id'] = auth()->id();
                            return $data;
                        })
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['kind'] ?? 'Update')
                        ->addActionLabel('Add entry')
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function taxonomyTab(): array
    {
        return [
            Section::make()->columns(2)->schema([
                Select::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('tagsRelation')
                    ->label('Tags')
                    ->multiple()
                    ->relationship('tagsRelation', 'name')
                    ->preload()
                    ->searchable()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(80),
                    ])
                    ->createOptionUsing(fn (array $data) => Tag::findOrCreateByName($data['name'])->id)
                    ->saveRelationshipsUsing(function ($component, $state) {
                        $component->getRecord()->tagsRelation()->sync($state ?? []);
                    }),

                Select::make('editorial_status')
                    ->label('Status')
                    ->options([
                        News::STATUS_DRAFT => 'Draft',
                        News::STATUS_IN_REVIEW => 'In review',
                        News::STATUS_SCHEDULED => 'Scheduled',
                        News::STATUS_PUBLISHED => 'Published',
                        News::STATUS_UNPUBLISHED => 'Unpublished',
                        News::STATUS_ARCHIVED => 'Archived',
                    ])
                    ->default(News::STATUS_DRAFT)
                    ->required()
                    ->live(),

                Select::make('article_type')
                    ->label('Article type')
                    ->options([
                        'reportage'  => 'Reportage (default news)',
                        'opinion'    => 'Opinion / editorial',
                        'review'     => 'Review',
                        'analysis'   => 'Analysis',
                        'background' => 'Background / explainer',
                    ])
                    ->default('reportage')
                    ->required()
                    ->helperText('Renders the matching Schema.org @type (ReportageNewsArticle, OpinionNewsArticle, …) so Google News + readers don\'t mistake an opinion piece for hard news.'),

                Toggle::make('is_featured')
                    ->label('Featured')
                    ->inline(false),

                Toggle::make('is_premium')
                    ->label('Premium (paywalled)')
                    ->inline(false)
                    ->helperText('Public visitors see a preview + Subscribe CTA. Subscribers see the full body.'),

                Toggle::make('is_sponsored')
                    ->label('Sponsored content')
                    ->inline(false)
                    ->live()
                    ->helperText('Marks the article as paid placement; renders a "Sponsored" banner.'),

                TextInput::make('sponsor_label')
                    ->label('Sponsor label')
                    ->maxLength(200)
                    ->placeholder('Presented by Acme Co.')
                    ->visible(fn ($get) => (bool) $get('is_sponsored'))
                    ->helperText('Free text shown in the sponsored banner.'),

                Select::make('series_id')
                    ->label('Article series')
                    ->options(fn () => \App\Models\ArticleSeries::active()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('— none —')
                    ->searchable()
                    ->helperText('Group this article into a multi-part series.'),

                TextInput::make('sort_in_series')
                    ->label('Order within series')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower values appear first on the series landing page.'),

                DateTimePicker::make('published_at')
                    ->label('Publish at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('When set with status "Scheduled", flips to Published automatically.')
                    ->required(fn ($get) => $get('editorial_status') === News::STATUS_SCHEDULED),

                DateTimePicker::make('unpublished_at')
                    ->label('Unpublish at')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Optional. Article disappears from the site at this time.'),
            ]),
        ];
    }

    private static function seoTab(): array
    {
        return [
            Section::make()->schema([
                TextInput::make('meta_title')
                    ->label('Meta title')
                    ->maxLength(255)
                    ->helperText('Falls back to the article title if blank. Aim for 50-60 chars.'),
                Textarea::make('meta_description')
                    ->label('Meta description')
                    ->rows(3)
                    ->maxLength(320)
                    ->helperText('Falls back to the excerpt if blank. Aim for 150-160 chars.'),
                TextInput::make('canonical_url')
                    ->label('Canonical URL')
                    ->url()
                    ->maxLength(500)
                    ->helperText('Only set if this article was originally published elsewhere.'),
                Select::make('fact_check_status')
                    ->label('Fact-check status')
                    ->options([
                        'verified'   => 'Verified',
                        'disputed'   => 'Disputed',
                        'unverified' => 'Unverified',
                    ])
                    ->placeholder('— none —')
                    ->helperText('Shown as a public badge on the article when set.'),
            ]),

            Section::make('Editorial gates')
                ->description('Optional pre-publish gates. When enabled, the article cannot flip to "Published" until the matching reviewer signs off via the buttons in the page header.')
                ->columns(2)
                ->schema([
                    Toggle::make('requires_fact_check')
                        ->label('Requires fact-check')
                        ->helperText('Blocks publish until fact-check status is "verified" + a fact-checker is recorded.')
                        ->columnSpanFull(),
                    \Filament\Forms\Components\Placeholder::make('fact_check_audit')
                        ->label('Fact-check audit')
                        ->columnSpanFull()
                        ->content(function ($record) {
                            if (! $record || ! $record->fact_checked_at) return '— not yet checked —';
                            $u = $record->fact_checked_by_user_id ? \App\Models\User::find($record->fact_checked_by_user_id) : null;
                            return ($u?->name ?: 'Unknown').' · '.$record->fact_checked_at?->format('M j, Y g:ia');
                        }),

                    Toggle::make('requires_legal_review')
                        ->label('Requires legal review')
                        ->helperText('Blocks publish until an admin approves the legal review.')
                        ->columnSpanFull(),
                    \Filament\Forms\Components\Placeholder::make('legal_audit')
                        ->label('Legal audit')
                        ->columnSpanFull()
                        ->content(function ($record) {
                            if (! $record || ! $record->legal_reviewed_at) return '— not yet reviewed —';
                            $u = $record->legal_reviewed_by_user_id ? \App\Models\User::find($record->legal_reviewed_by_user_id) : null;
                            $verdict = $record->legal_review_status ?: 'pending';
                            $line = strtoupper($verdict).' · '.($u?->name ?: 'Unknown').' · '.$record->legal_reviewed_at?->format('M j, Y g:ia');
                            if ($record->legal_review_notes) {
                                $line .= "\n".\Illuminate\Support\Str::limit($record->legal_review_notes, 280);
                            }
                            return $line;
                        }),
                ]),
        ];
    }

    private static function citationsTab(): array
    {
        return [
            Section::make()
                ->description('Source links rendered in the "Sources" block at the foot of the article. Use one entry per citation.')
                ->schema([
                    Repeater::make('sources')
                        ->label(false)
                        ->relationship('sources')
                        ->schema([
                            TextInput::make('label')
                                ->required()
                                ->maxLength(255)
                                ->helperText('Short description of what this source substantiates.')
                                ->columnSpan(2),
                            TextInput::make('publisher')
                                ->maxLength(200)
                                ->helperText('e.g., "Reuters", "DOJ press release".'),
                            TextInput::make('url')
                                ->url()
                                ->maxLength(500)
                                ->helperText('Public link, if any.'),
                            TextInput::make('sort')
                                ->numeric()
                                ->default(0)
                                ->helperText('Lower numbers first.'),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? 'Source')
                        ->orderColumn('sort')
                        ->addActionLabel('Add citation')
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function languageTab(): array
    {
        return [
            Section::make()
                ->description('Each News row is in one language. Use "Translation siblings" below to see / create translations of this piece — they share a translation group but each has their own headline, body, and slug.')
                ->schema([
                    Select::make('locale')
                        ->label('Language')
                        ->options(config('locales.supported', ['en' => 'English']))
                        ->default(config('locales.default', 'en'))
                        ->required(),

                    Select::make('auto_translate_locales')
                        ->label('Auto-translate on publish')
                        ->multiple()
                        ->options(function ($get) {
                            $supported = config('locales.supported', []);
                            $self = $get('locale') ?: config('locales.default', 'en');
                            // Don't offer to translate the article into its own language.
                            return array_diff_key($supported, array_flip([$self]));
                        })
                        ->helperText('Selected languages get a published translation sibling produced by the article.translate Assistant the moment this article transitions into Published. Already-translated locales are skipped on re-publish.'),

                    \Filament\Forms\Components\Placeholder::make('siblings')
                        ->label('Translation siblings')
                        ->content(function ($record) {
                            if (! $record) {
                                return 'Save this article first to manage translations.';
                            }
                            $siblings = $record->translationSiblings()->get();
                            if ($siblings->isEmpty()) {
                                return 'No translations yet. Use the "Create translation" action above to start one.';
                            }
                            $supported = config('locales.supported', []);
                            return $siblings->map(function ($s) use ($supported) {
                                $label = $supported[$s->locale ?? 'en'] ?? ($s->locale ?? 'en');
                                $editUrl = \App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $s->id]);
                                return sprintf('• %s — %s (edit)', $label, e($s->title));
                            })->implode("\n");
                        }),
                ]),
        ];
    }

    private static function headlinesTab(): array
    {
        return [
            Section::make()
                ->description('Optional alternate headlines. The public site picks one per session weighted by CTR with an explore factor for new variants. Mark one variant as default.')
                ->schema([
                    Repeater::make('headlines')
                        ->label(false)
                        ->relationship('headlines')
                        ->schema([
                            TextInput::make('variant')
                                ->label('Headline text')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Toggle::make('is_default')
                                ->label('Default')
                                ->helperText('The fallback when no variant has enough impressions.'),
                            TextInput::make('impressions')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->dehydrated()
                                ->helperText('Auto-tracked.'),
                            TextInput::make('clicks')
                                ->numeric()
                                ->default(0)
                                ->disabled()
                                ->dehydrated()
                                ->helperText('Auto-tracked.'),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['variant'] ?? 'Variant')
                        ->addActionLabel('Add variant')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
