<?php

namespace App\Filament\Pages;

use App\Models\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Site settings';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.site-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->data = Settings::pluck('value', 'key')->toArray();
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()->columnSpanFull()->tabs([
                    Tab::make('General')->schema([
                        Section::make()->columns(2)->schema([
                            TextInput::make('site_name')->required()->maxLength(120),
                            TextInput::make('site_email')->email()->required(),
                            Textarea::make('site_description')->rows(3)->columnSpanFull(),
                            TextInput::make('copyright_text')->columnSpanFull(),
                        ]),
                    ]),
                    Tab::make('Branding')->schema([
                        FileUpload::make('site_logo')
                            ->image()->disk('public')->directory('settings')
                            ->acceptedFileTypes($imageTypes)->maxSize(2048),
                        FileUpload::make('site_logo_white')
                            ->image()->disk('public')->directory('settings')
                            ->acceptedFileTypes($imageTypes)->maxSize(2048),
                        FileUpload::make('admin_logo')
                            ->image()->disk('public')->directory('settings')
                            ->acceptedFileTypes($imageTypes)->maxSize(2048),
                        FileUpload::make('site_favicon')
                            ->image()->disk('public')->directory('settings')
                            ->acceptedFileTypes($imageTypes)->maxSize(1024),
                    ]),
                    Tab::make('Social')->schema([
                        TextInput::make('facebook_url')->url(),
                        TextInput::make('twitter_url')->url(),
                        TextInput::make('instagram_url')->url(),
                        TextInput::make('youtube_url')->url(),
                        TextInput::make('linkedin_url')->url(),
                    ]),
                    Tab::make('Code snippets')->schema([
                        Textarea::make('site_header_code')
                            ->label('Header code (analytics, etc.)')
                            ->rows(5)
                            ->helperText('Raw HTML/JS injected into <head>. Admin-only.'),
                        Textarea::make('site_footer_code')
                            ->rows(5)
                            ->helperText('Raw HTML/JS injected before </body>. Admin-only.'),
                    ]),
                    Tab::make('Analytics')->schema([
                        Select::make('analytics_provider')
                            ->label('Analytics provider')
                            ->options([
                                ''           => 'None',
                                'ga4'        => 'Google Analytics 4',
                                'plausible'  => 'Plausible',
                                'fathom'     => 'Fathom',
                                'cloudflare' => 'Cloudflare Web Analytics',
                                'umami'      => 'Umami',
                                'custom'     => 'Custom (paste raw snippet)',
                            ])
                            ->default('')
                            ->live()
                            ->helperText('Snippet is bot-filtered and respects DNT / Sec-GPC headers.'),

                        TextInput::make('analytics_ga4_id')
                            ->label('Measurement ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->visible(fn ($get) => $get('analytics_provider') === 'ga4')
                            ->helperText('Found in your GA4 property under Admin → Data Streams.'),

                        TextInput::make('analytics_plausible_domain')
                            ->label('Plausible domain')
                            ->placeholder('your-site.com')
                            ->visible(fn ($get) => $get('analytics_provider') === 'plausible'),
                        TextInput::make('analytics_plausible_host')
                            ->label('Plausible host (optional)')
                            ->placeholder('plausible.io')
                            ->visible(fn ($get) => $get('analytics_provider') === 'plausible')
                            ->helperText('Override only when self-hosting. Leave blank for plausible.io.'),

                        TextInput::make('analytics_fathom_site_id')
                            ->label('Fathom site ID')
                            ->placeholder('ABCDEFGH')
                            ->visible(fn ($get) => $get('analytics_provider') === 'fathom'),

                        TextInput::make('analytics_cloudflare_token')
                            ->label('Cloudflare beacon token')
                            ->placeholder('e.g. 8a1b…')
                            ->visible(fn ($get) => $get('analytics_provider') === 'cloudflare')
                            ->helperText('Cloudflare dashboard → Analytics & Logs → Web Analytics → site → token.'),

                        TextInput::make('analytics_umami_website_id')
                            ->label('Umami website ID')
                            ->visible(fn ($get) => $get('analytics_provider') === 'umami'),
                        TextInput::make('analytics_umami_script_url')
                            ->label('Umami script URL')
                            ->placeholder('https://analytics.example.com/script.js')
                            ->visible(fn ($get) => $get('analytics_provider') === 'umami'),

                        Textarea::make('analytics_custom_html')
                            ->label('Custom snippet')
                            ->rows(8)
                            ->visible(fn ($get) => $get('analytics_provider') === 'custom')
                            ->helperText('Paste the vendor-supplied <script> tag(s) verbatim. Injected into <head>.'),
                    ]),
                    Tab::make('Storage')->schema([
                        Select::make('site_storage')
                            ->options([
                                'public' => 'Local (public disk)',
                                's3' => 'Amazon S3',
                            ])
                            ->default('public')
                            ->required(),
                    ]),
                    Tab::make('Maintenance')->schema([
                        Toggle::make('maintenance_mode')->label('Maintenance mode'),
                        Textarea::make('maintenance_description')->rows(3),
                    ]),
                    Tab::make('AI moderation')->schema([
                        Toggle::make('ai_moderation_enabled')
                            ->label('Auto-classify new comments')
                            ->helperText('Runs each new comment through the configured AI provider. ALLOW → published; REVIEW → moderation queue; REJECT → marked spam. Falls back to the queue on any provider error.'),
                        Toggle::make('ai_lint_required')
                            ->label('Require article lint to pass before publish')
                            ->helperText('When on, the publish action is gated on the AI lint reporting no critical findings. Editors can still override.'),
                        Toggle::make('ai_auto_classify_enabled')
                            ->label('Auto-classify articles into topics on publish')
                            ->helperText('When an article flips to Published, runs the editorial.topic_classifier agent to pin it to the best matching topic landing page. Skipped if the article is already on a topic.'),
                    ]),

                    Tab::make('Anti-spam / CAPTCHA')->schema([
                        Toggle::make('comments_allow_guests')
                            ->label('Allow guest comments (no sign-in required)')
                            ->live()
                            ->helperText('When on, anonymous visitors can post a comment if they pass the CAPTCHA challenge below. They\'re asked for a name + email; the email is stored for moderation but never shown publicly.'),

                        Select::make('captcha_provider')
                            ->label('CAPTCHA provider')
                            ->options([
                                ''          => 'None',
                                'recaptcha' => 'Google reCAPTCHA v2 / v3',
                                'turnstile' => 'Cloudflare Turnstile',
                            ])
                            ->default('')
                            ->live()
                            ->helperText('Required when guest comments are enabled. Cloudflare Turnstile is privacy-friendlier and free up to typical site volume.'),

                        TextInput::make('captcha_site_key')
                            ->label('Site key (public)')
                            ->maxLength(255)
                            ->visible(fn ($get) => in_array($get('captcha_provider'), ['recaptcha', 'turnstile'], true))
                            ->helperText('Embedded into the public form. Safe to share; visible in page source.'),

                        TextInput::make('captcha_secret_key')
                            ->label('Secret key (server-side)')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn ($get) => in_array($get('captcha_provider'), ['recaptcha', 'turnstile'], true))
                            ->helperText('Used server-side to verify each submission. Never exposed in HTML.'),
                    ]),

                    Tab::make('AI defaults')->schema([
                        Select::make('default_image_provider_id')
                            ->label('Default image-generation provider')
                            ->options(fn () => \App\Models\AiProvider::active()
                                ->whereIn('kind', [\App\Models\AiProvider::KIND_OPENAI, \App\Models\AiProvider::KIND_GOOGLE_IMAGEN])
                                ->whereNotNull('image_model')
                                ->where('image_model', '!=', '')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->placeholder('— first matching provider wins —')
                            ->searchable()
                            ->helperText('News agents and other image-using features fall back to this when no per-feature override is set. Configure providers under AI → Providers.'),

                        TextInput::make('article_tts_voice')
                            ->label('Read-aloud voice')
                            ->placeholder('alloy')
                            ->maxLength(60)
                            ->helperText('OpenAI TTS voice key for the per-article read-aloud audio. Common: alloy, echo, fable, onyx, nova, shimmer. Leave blank for "alloy".'),
                    ]),

                    Tab::make('Ask paywall')->schema([
                        Section::make()
                            ->description('Tier-aware monthly cap on the public "Ask the newsroom" feature. Leave a value blank to fall back to the env defaults in config/paywall.php. Use -1 for unlimited.')
                            ->columns(2)
                            ->schema([
                                Toggle::make('paywall_ask_enabled')
                                    ->label('Enable Ask paywall')
                                    ->columnSpanFull()
                                    ->helperText('Off = legacy IP rate-limit only (10/hour, 30/day). On = monthly cap per tier kicks in.'),

                                TextInput::make('paywall_ask_anonymous_monthly')
                                    ->label('Anonymous quota')
                                    ->numeric()
                                    ->minValue(-1)
                                    ->placeholder('e.g. 3')
                                    ->helperText('Questions per month before anonymous visitors hit the paywall. 0 forces sign-up. -1 = unlimited.'),

                                TextInput::make('paywall_ask_registered_monthly')
                                    ->label('Registered (free) quota')
                                    ->numeric()
                                    ->minValue(-1)
                                    ->placeholder('e.g. 10')
                                    ->helperText('Logged-in non-subscribers. -1 = unlimited.'),

                                TextInput::make('paywall_ask_subscriber_monthly')
                                    ->label('Subscriber quota')
                                    ->numeric()
                                    ->minValue(-1)
                                    ->placeholder('e.g. 100')
                                    ->helperText('Active Stripe subscribers. -1 = unlimited.'),
                            ]),
                    ]),

                    Tab::make('Image CDN')->schema([
                        Section::make()
                            ->description('Routes hero / card images through a CDN that resizes + format-negotiates on the fly. AVIF and WebP are emitted automatically in the responsive `<picture>` partial once a provider is configured. Leaving the provider on "local" preserves existing behavior.')
                            ->columns(2)
                            ->schema([
                                Select::make('image_cdn_provider')
                                    ->label('CDN provider')
                                    ->options([
                                        'local'      => 'Local (no CDN)',
                                        'cloudflare' => 'Cloudflare Image Resizing',
                                        'imgix'      => 'imgix',
                                    ])
                                    ->default('local')
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('image_cdn_zone')
                                    ->label('Cloudflare zone')
                                    ->placeholder('https://your-site.com')
                                    ->url()->maxLength(255)
                                    ->helperText('Leave blank to use APP_URL. Required only when provider is Cloudflare.'),
                                TextInput::make('image_cdn_imgix_source')
                                    ->label('imgix source')
                                    ->placeholder('mysite-prod')
                                    ->maxLength(120)
                                    ->helperText('imgix subdomain (without .imgix.net). Required when provider is imgix.'),
                                TextInput::make('image_cdn_quality')
                                    ->label('Default quality')
                                    ->numeric()
                                    ->minValue(1)->maxValue(100)
                                    ->placeholder('85')
                                    ->helperText('1..100. Cloudflare default 85, imgix default 80.'),
                            ]),
                    ]),

                    Tab::make('Apple News')->schema([
                        Section::make()
                            ->description('Credentials for the Apple News Publisher REST API. Once filled in, a "Push to Apple News" button appears on each article edit page. The downloadable .json export works without these.')
                            ->columns(2)
                            ->schema([
                                TextInput::make('apple_news_channel_id')
                                    ->label('Channel ID')
                                    ->maxLength(120)
                                    ->placeholder('uuid from News Publisher → API Channels'),
                                TextInput::make('apple_news_api_key_id')
                                    ->label('API key ID')
                                    ->maxLength(120),
                                TextInput::make('apple_news_api_secret')
                                    ->label('API key secret')
                                    ->password()->revealable()->maxLength(255)
                                    ->helperText('Base64-encoded shared secret. Apple shows this once when you mint the key.'),
                            ]),
                    ]),

                    Tab::make('Pay-per-article')->schema([
                        Section::make()
                            ->description('Let non-subscribers buy access to a single premium article via Stripe Checkout. The button appears on the paywall block only when this is enabled and Stripe is configured.')
                            ->columns(2)
                            ->schema([
                                Toggle::make('paywall_per_article_enabled')
                                    ->label('Enable single-article purchases')
                                    ->columnSpanFull(),
                                TextInput::make('paywall_per_article_price_cents')
                                    ->label('Price (cents)')
                                    ->numeric()
                                    ->minValue(50)
                                    ->placeholder('199')
                                    ->helperText('In cents. Stripe minimum is 50¢ in USD.'),
                                TextInput::make('paywall_per_article_ttl_days')
                                    ->label('Access TTL (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('0')
                                    ->helperText('0 = perpetual access for the buyer. >0 = access expires after N days.'),
                            ]),
                    ]),

                    Tab::make('Distribution')->schema([
                        Section::make('Public social channels')
                            ->description('Auto-post each newly-published article to these channels. Each channel has an independent enable toggle so you can stage credentials before going live.')
                            ->schema([

                                // Mastodon ------------------------------------------------------
                                Toggle::make('social_mastodon_enabled')->label('Enable Mastodon'),
                                TextInput::make('mastodon_instance')
                                    ->label('Mastodon instance URL')
                                    ->placeholder('https://mastodon.social')
                                    ->url()
                                    ->maxLength(255)
                                    ->helperText('The instance the newsroom posts FROM.'),
                                TextInput::make('mastodon_access_token')
                                    ->label('Mastodon access token')
                                    ->password()->revealable()->maxLength(255)
                                    ->helperText('Token with write:statuses scope. Generate at /settings/applications.'),

                                // Bluesky -------------------------------------------------------
                                Toggle::make('social_bluesky_enabled')->label('Enable Bluesky'),
                                TextInput::make('bluesky_handle')
                                    ->label('Bluesky handle')
                                    ->placeholder('mysite.bsky.social')
                                    ->maxLength(120),
                                TextInput::make('bluesky_app_password')
                                    ->label('Bluesky app password')
                                    ->password()->revealable()->maxLength(120)
                                    ->helperText('Generate under Settings → App Passwords. NEVER paste your main password here.'),
                                TextInput::make('bluesky_pds_url')
                                    ->label('PDS URL')
                                    ->placeholder('https://bsky.social')
                                    ->url()->maxLength(255)
                                    ->helperText('Leave blank for the public Bluesky PDS.'),

                                // X / Twitter ---------------------------------------------------
                                Toggle::make('social_x_enabled')->label('Enable X (Twitter)'),
                                TextInput::make('x_api_key')
                                    ->label('X API key')->maxLength(255),
                                TextInput::make('x_api_secret')
                                    ->label('X API secret')
                                    ->password()->revealable()->maxLength(255),
                                TextInput::make('x_access_token')
                                    ->label('X access token')
                                    ->password()->revealable()->maxLength(255),
                                TextInput::make('x_access_secret')
                                    ->label('X access secret')
                                    ->password()->revealable()->maxLength(255)
                                    ->helperText('All four credentials issued at developer.x.com → Project → Keys & Tokens.'),

                                // Threads -------------------------------------------------------
                                Toggle::make('social_threads_enabled')->label('Enable Threads'),
                                TextInput::make('threads_user_id')
                                    ->label('Threads user id')->maxLength(64),
                                TextInput::make('threads_access_token')
                                    ->label('Threads access token')
                                    ->password()->revealable()->maxLength(500)
                                    ->helperText('Long-lived token from the Meta app dashboard.'),

                                // LinkedIn ------------------------------------------------------
                                Toggle::make('social_linkedin_enabled')->label('Enable LinkedIn'),
                                TextInput::make('linkedin_author_urn')
                                    ->label('LinkedIn author URN')
                                    ->placeholder('urn:li:organization:1234567 or urn:li:person:abcd')
                                    ->maxLength(120),
                                TextInput::make('linkedin_access_token')
                                    ->label('LinkedIn access token')
                                    ->password()->revealable()->maxLength(500)
                                    ->helperText('OAuth 2 token with w_member_social or w_organization_social scope.'),

                                // Facebook ------------------------------------------------------
                                Toggle::make('social_facebook_enabled')->label('Enable Facebook page'),
                                TextInput::make('facebook_page_id')
                                    ->label('Facebook page id')->maxLength(64),
                                TextInput::make('facebook_page_access_token')
                                    ->label('Facebook page access token')
                                    ->password()->revealable()->maxLength(500)
                                    ->helperText('Long-lived page token with pages_manage_posts scope.'),
                            ]),

                        Section::make('Internal / breaking-news channels')
                            ->description('Webhook-driven channels for the newsroom Slack/Discord/Telegram. Same auto-publish trigger; useful for pinging editors when an article goes live.')
                            ->schema([
                                Toggle::make('social_slack_enabled')->label('Enable Slack'),
                                TextInput::make('slack_webhook_url')
                                    ->label('Slack incoming-webhook URL')
                                    ->password()->revealable()
                                    ->placeholder('https://hooks.slack.com/services/T.../B.../...')
                                    ->maxLength(500),

                                Toggle::make('social_discord_enabled')->label('Enable Discord'),
                                TextInput::make('discord_webhook_url')
                                    ->label('Discord webhook URL')
                                    ->password()->revealable()
                                    ->placeholder('https://discord.com/api/webhooks/{id}/{token}')
                                    ->maxLength(500),

                                Toggle::make('social_telegram_enabled')->label('Enable Telegram'),
                                TextInput::make('telegram_bot_token')
                                    ->label('Telegram bot token')
                                    ->password()->revealable()->maxLength(255)
                                    ->helperText('From @BotFather. Bot must be added to the target chat as admin.'),
                                TextInput::make('telegram_chat_id')
                                    ->label('Telegram chat id')
                                    ->placeholder('-1001234567890 or @newschannel')
                                    ->maxLength(120),
                            ]),
                    ]),
                ]),
            ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ($state as $key => $value) {
            Settings::updateOrCreate(
                ['key' => $key],
                ['value' => $this->normalizeSettingValue($value)]
            );
        }

        // Reload the form so freshly-persisted file paths replace temp state.
        $this->data = Settings::pluck('value', 'key')->toArray();
        $this->form->fill($this->data);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * Coerce any value coming out of Filament's form state into the string we
     * actually want stored in the `settings.value` column. Custom pages don't
     * run Filament's resource-save lifecycle, so FileUpload components can
     * leave a livewire-tmp UploadedFile on the state — persist those here
     * before storing the resulting path.
     */
    private function normalizeSettingValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return $this->storeUploadedFile($value);
        }
        if (is_array($value)) {
            if ($value === []) {
                return '';
            }
            $first = reset($value);
            if ($first instanceof \Illuminate\Http\UploadedFile) {
                return $this->storeUploadedFile($first);
            }
            if (is_string($first)) {
                return $first;
            }
            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Re-validate the file's actual MIME server-side before persisting.
     * Filament's FileUpload acceptedFileTypes is enforced in the form, but
     * a custom-page save bypasses Filament's server-side validation pipeline,
     * so we double-check here. Notably, SVG is rejected — although it's
     * allowed for favicons in the form, an SVG with embedded JavaScript
     * would XSS via <img> rendering, so we only accept raster images at
     * persist time.
     */
    private function storeUploadedFile(\Illuminate\Http\UploadedFile $file): string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, $allowedMimes, true)) {
            \Filament\Notifications\Notification::make()
                ->title('Unsupported file type')
                ->body("Got {$mime}; allowed: JPEG, PNG, GIF, WebP.")
                ->danger()
                ->send();
            return '';
        }

        return (string) $file->store('settings', 'public');
    }
}
