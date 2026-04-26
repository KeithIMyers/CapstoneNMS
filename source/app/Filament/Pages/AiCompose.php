<?php

namespace App\Filament\Pages;

use App\Models\AgentRun;
use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Models\Category;
use App\Services\Ai\Agent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;

/**
 * AI-first front door for new articles. The editor describes the story
 * in plain English, optionally pastes source URLs the agent should
 * read, and clicks Compose. The editorial.compose_article agent fetches
 * each URL, drafts the piece, calls create_draft, and we redirect to
 * the resulting edit page.
 *
 * Lives at /admin/ai-compose with a top-level "Compose with AI" entry
 * in the Content nav so it's the first thing reaching for "new article"
 * sees when AI is on.
 */
class AiCompose extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $title = 'Compose with AI';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.ai-compose';

    public const AGENT_KEY = 'editorial.compose_article';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        if (! (auth()->user()?->isAuthor() ?? false)) {
            return false;
        }
        // Hide entirely when no provider is configured — pointless otherwise.
        return AiProvider::active()->exists();
    }

    public function mount(): void
    {
        $this->form->fill([
            'prompt'        => '',
            'source_urls'   => '',
            'category_id'   => Category::query()->orderBy('cat_order')->value('id'),
            'desired_words' => 600,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description('Provide the agent with several source URLs covering the same story from different outlets — the more diverse the better. The agent reads each one, identifies which facts are corroborated across sources, flags contested or single-sourced claims, and produces a draft with inline attribution. Every successfully-fetched URL is auto-cited in the article\'s Sources block.')
                    ->schema([
                        Textarea::make('prompt')
                            ->label('What should the article cover?')
                            ->required()
                            ->rows(4)
                            ->maxLength(4000)
                            ->placeholder('e.g., A 600-word explainer on the Senate energy bill\'s tax credit changes, focused on what it means for residential solar installers in the Pacific Northwest.'),

                        Textarea::make('source_urls')
                            ->label('Source URLs (one per line)')
                            ->rows(6)
                            ->maxLength(4000)
                            ->placeholder("https://reuters.com/business/energy/...\nhttps://wsj.com/articles/...\nhttps://congress.gov/bill/119/text\nhttps://eia.gov/electricity/data.php")
                            ->helperText('Recommended: 2–5 URLs from outlets with different editorial angles. Single-URL composes work too but bias and missed-fact risk goes up.'),

                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),

                        TextInput::make('desired_words')
                            ->label('Target word count')
                            ->numeric()
                            ->minValue(150)
                            ->maxValue(2500)
                            ->default(600),
                    ]),
            ]);
    }

    /** Parsed list of URLs from the source_urls textarea. */
    private function parseUrls(string $raw): array
    {
        return collect(preg_split("/\r?\n/", $raw))
            ->map(fn ($l) => trim($l))
            ->filter(fn ($l) => $l !== '' && preg_match('~^https?://~i', $l))
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewSources')
                ->label('Preview sources')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(fn () => $this->previewSources()),

            Action::make('compose')
                ->label('Compose draft')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action(fn () => $this->compose()),
        ];
    }

    /**
     * Cheap pre-flight: hit each URL with the same fetch_url tool the
     * agent will use, surface the resolved titles + status as a Filament
     * notification. Lets editors catch a typoed URL or a paywalled source
     * before burning agent tokens on a doomed compose.
     */
    protected function previewSources(): void
    {
        $state = $this->form->getState();
        $urls = $this->parseUrls((string) ($state['source_urls'] ?? ''));

        if (empty($urls)) {
            Notification::make()
                ->title('Add some URLs first')
                ->warning()
                ->send();
            return;
        }

        $tool = new \App\Services\Ai\Tools\FetchUrlTool();
        $lines = [];
        foreach ($urls as $u) {
            $obs = $tool->execute(['url' => $u]);
            if (str_starts_with($obs, 'ERROR:')) {
                $lines[] = '✗ '.\Illuminate\Support\Str::limit($u, 60).' — '.\Illuminate\Support\Str::limit(substr($obs, 6), 80);
                continue;
            }
            // FetchUrlTool's success format: "URL: …\nTitle: …\n\n…body…"
            $title = '';
            if (preg_match('/^Title:\s*(.+)$/m', $obs, $m)) {
                $title = trim($m[1]);
            }
            $lines[] = '✓ '.\Illuminate\Support\Str::limit($u, 60)
                .($title !== '' ? ' — '.\Illuminate\Support\Str::limit($title, 80) : '');
        }

        Notification::make()
            ->title(count($urls).' source(s) checked')
            ->body(implode("\n", $lines))
            ->success()
            ->persistent()
            ->send();
    }

    protected function compose(): void
    {
        $state = $this->form->getState();

        $prompt = trim((string) ($state['prompt'] ?? ''));
        if ($prompt === '') {
            Notification::make()->title('Add a prompt first')->warning()->send();
            return;
        }

        // Make sure the agent exists. If not, bootstrap it inline so the
        // page works the moment AI is configured — no separate seed step.
        $this->ensureAgentExists();

        $urls = $this->parseUrls((string) ($state['source_urls'] ?? ''));

        $categorySlug = Category::find($state['category_id'])?->slug ?? null;
        $words = (int) ($state['desired_words'] ?? 600);

        $input = "REQUEST:\n{$prompt}\n\n"
            ."TARGET LENGTH: ~{$words} words\n"
            .($categorySlug ? "CATEGORY SLUG: {$categorySlug}\n" : '')
            .(empty($urls)
                ? "SOURCE URLS: (none — draft from REQUEST alone, do not invent citation URLs)\n"
                : "SOURCE URLS (".count($urls)." total — read each, build a fact matrix, attribute inline, and cite every successful fetch):\n  - "
                    .implode("\n  - ", $urls)."\n");

        $run = app(Agent::class)->run(
            agentKey: self::AGENT_KEY,
            input: $input,
            userId: auth()->id(),
        );

        if ($run->status !== AgentRun::STATUS_DONE) {
            Notification::make()
                ->title('Compose failed')
                ->body(\Illuminate\Support\Str::limit((string) $run->error_message, 600))
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        // The compose agent's final answer is "OK | id=N | edit_url=…"
        // followed by a synthesis note. Surface the note in the success
        // notification — it's the editor's quick read on whether the
        // sources were balanced.
        $output = (string) $run->final_output;
        if (preg_match('/id=(\d+)/', $output, $m)) {
            $newsId = (int) $m[1];

            $synthesisNote = '';
            if (preg_match('/edit_url=\S+\s*\|?\s*(.+)$/s', $output, $n)) {
                $synthesisNote = trim($n[1]);
            }

            $body = $run->iterations.' steps · '
                .number_format($run->tokens_in_total + $run->tokens_out_total).' tokens'
                .(count($urls) ? ' · '.count($urls).' sources' : '');

            Notification::make()
                ->title('Draft created')
                ->body($synthesisNote
                    ? \Illuminate\Support\Str::limit($synthesisNote, 600)."\n\n".$body
                    : $body)
                ->success()
                ->persistent()
                ->send();

            redirect(\App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $newsId]));
            return;
        }

        // Agent finished but didn't return the marker — show the run anyway.
        Notification::make()
            ->title('Compose finished but no draft id was returned')
            ->body('Open the run record to inspect the transcript.')
            ->warning()
            ->send();
        redirect(\App\Filament\Resources\AgentRuns\AgentRunResource::getUrl('view', ['record' => $run->id]));
    }

    /**
     * Lazy-create the compose agent from the central DefaultAgentDefinitions
     * registry if an admin hasn't seeded it yet. Keeps "open Compose page
     * on a fresh install" working without a separate seed click — and
     * means prompt edits to the registry only need to land once.
     */
    private function ensureAgentExists(): void
    {
        if (AiAgent::where('key', self::AGENT_KEY)->exists()) {
            return;
        }
        $defaults = \App\Services\Ai\DefaultAgentDefinitions::get(self::AGENT_KEY);
        if (! $defaults) {
            // Should never happen since the key is in the registry, but
            // belt-and-braces: a plain assistant beats a 500.
            $defaults = [
                'name' => 'Article composer',
                'description' => 'Drafts a new article from a prompt + optional source URLs.',
                'system_prompt' => 'You are a newsroom feature writer. Draft articles in inverted-pyramid AP style.',
                'tool_keys' => ['fetch_url', 'create_draft', 'add_source'],
                'max_iterations' => 16,
                'temperature' => 0.35,
                'max_tokens_per_step' => 3000,
            ];
        }
        AiAgent::create($defaults + ['key' => self::AGENT_KEY, 'is_active' => true]);
    }

}
