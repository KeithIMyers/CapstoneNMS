<?php

namespace App\Filament\Resources\News\Pages;

use App\Filament\Resources\News\NewsResource;
use App\Models\News;
use App\Models\NewsHeadline;
use App\Services\Ai\AiException;
use App\Services\Ai\Assistant;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditNews extends EditRecord
{
    protected static string $resource = NewsResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\ArticleLintFindings::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getWidgetData(): array
    {
        return [
            'record' => $this->record,
        ];
    }

    /**
     * Enforce the "lint must pass before publish" toggle. We only block
     * when the editor is actively flipping the article to the published
     * state — saving an in-review or scheduled draft is always fine.
     *
     * The session findings array is the source of truth: if the editor
     * has dismissed it (Dismiss button on the findings panel) or re-run
     * the lint to a clean result, this gate clears.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $isPublishing = ($data['editorial_status'] ?? null) === News::STATUS_PUBLISHED
            && $this->record->editorial_status !== News::STATUS_PUBLISHED;

        if (! $isPublishing) return $data;

        // Editorial gates (fact-check, legal review). The model
        // reports blockers based on the form-derived state, not the
        // saved record — that way an editor flipping requires_*
        // off in the same save can still publish without a
        // round-trip.
        $gateProbe = $this->record->replicate(['id'])->forceFill([
            'requires_fact_check'   => (bool) ($data['requires_fact_check']   ?? false),
            'fact_check_status'     => $data['fact_check_status'] ?? $this->record->fact_check_status,
            'fact_checked_by_user_id' => $this->record->fact_checked_by_user_id,
            'requires_legal_review' => (bool) ($data['requires_legal_review'] ?? false),
            'legal_review_status'   => $this->record->legal_review_status,
        ]);
        $blockers = $gateProbe->publishBlockers();
        if (! empty($blockers)) {
            Notification::make()
                ->title('Publish blocked by editorial gates')
                ->body(implode("\n", $blockers))
                ->danger()
                ->persistent()
                ->send();
            $this->halt();
        }

        $required = function_exists('getcong') ? getcong('ai_lint_required') : null;
        if (! in_array(strtolower((string) $required), ['1', 'true', 'on', 'yes'], true)) {
            return $data;
        }

        $findings = (array) session("lint_findings_{$this->record->id}", []);
        $criticals = collect($findings)->where('severity', 'critical')->count();
        if ($criticals === 0) return $data;

        Notification::make()
            ->title('Publish blocked')
            ->body("Pre-publish lint reports {$criticals} critical finding(s). Resolve them, re-run the lint, or dismiss the panel to proceed.")
            ->danger()
            ->persistent()
            ->send();

        $this->halt();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make($this->aiActions())
                ->label('AI assist')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->button(),

            Action::make('analytics')
                ->label('Analytics')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(fn () => NewsResource::getUrl('analytics', ['record' => $this->record->id])),

            Action::make('generatePodcast')
                ->label('Generate podcast')
                ->icon('heroicon-o-microphone')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Generate a NotebookLM-style podcast?')
                ->modalDescription('Two AI hosts will discuss this article in a short conversational format. Audio replaces any existing podcast on the article.')
                ->action(function () {
                    $r = app(\App\Services\Podcast\ArticlePodcastGenerator::class)->generate($this->record);
                    Notification::make()
                        ->title($r['ok'] ? 'Podcast ready' : 'Podcast failed')
                        ->body(\Illuminate\Support\Str::limit($r['message'] ?? '', 600))
                        ->color($r['ok'] ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                    if ($r['ok']) {
                        $this->record->refresh();
                    }
                }),

            Action::make('generateReadAloud')
                ->label('Generate read-aloud')
                ->icon('heroicon-o-speaker-wave')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Generate a single-voice read-aloud?')
                ->modalDescription('A straight narration of the article body for accessibility and on-the-go listening. Distinct from the podcast (which is two-host conversational). Replaces any existing read-aloud audio.')
                ->action(function () {
                    $r = app(\App\Services\Tts\ArticleTtsGenerator::class)->generate($this->record);
                    Notification::make()
                        ->title($r['ok'] ? 'Read-aloud ready' : 'Read-aloud failed')
                        ->body(\Illuminate\Support\Str::limit($r['message'] ?? '', 600))
                        ->color($r['ok'] ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                    if ($r['ok']) {
                        $this->record->refresh();
                    }
                }),

            Action::make('approveFactCheck')
                ->label('Sign off fact-check')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->requires_fact_check
                    && (auth()->user()?->isEditor() ?? false))
                ->requiresConfirmation()
                ->modalHeading('Mark this article fact-checked?')
                ->modalDescription('Records you as the fact-checker and flips the article fact_check_status to verified. The article still needs an editor to flip editorial_status to published.')
                ->action(function () {
                    $this->record->forceFill([
                        'fact_check_status'       => 'verified',
                        'fact_checked_by_user_id' => auth()->id(),
                        'fact_checked_at'         => now(),
                    ])->save();
                    Notification::make()->title('Fact-check signed off')->success()->send();
                    $this->fillForm();
                }),

            Action::make('approveLegalReview')
                ->label('Approve legal review')
                ->icon('heroicon-o-scale')
                ->color('success')
                ->visible(fn () => $this->record->requires_legal_review
                    && (auth()->user()?->isAdmin() ?? false))
                ->schema([
                    \Filament\Forms\Components\Textarea::make('legal_review_notes')
                        ->label('Reviewer notes (optional)')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) {
                    $this->record->forceFill([
                        'legal_review_status'        => 'approved',
                        'legal_reviewed_by_user_id'  => auth()->id(),
                        'legal_reviewed_at'          => now(),
                        'legal_review_notes'         => $data['legal_review_notes'] ?? null,
                    ])->save();
                    Notification::make()->title('Legal review approved')->success()->send();
                    $this->fillForm();
                }),

            Action::make('rejectLegalReview')
                ->label('Request legal changes')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn () => $this->record->requires_legal_review
                    && (auth()->user()?->isAdmin() ?? false))
                ->schema([
                    \Filament\Forms\Components\Textarea::make('legal_review_notes')
                        ->label('What needs changing?')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                ])
                ->action(function (array $data) {
                    $this->record->forceFill([
                        'legal_review_status'        => 'changes_requested',
                        'legal_reviewed_by_user_id'  => auth()->id(),
                        'legal_reviewed_at'          => now(),
                        'legal_review_notes'         => $data['legal_review_notes'],
                    ])->save();
                    Notification::make()->title('Changes requested — author notified via the activity log.')->warning()->send();
                    $this->fillForm();
                }),

            Action::make('appleNewsExport')
                ->label('Apple News (.json)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $anf = (new \App\Services\Apple\AppleNewsFormatGenerator())->generate($this->record);
                    $json = json_encode($anf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $name = 'anf-'.$this->record->id.'-'.\Illuminate\Support\Str::slug($this->record->slug).'.json';
                    return response()->streamDownload(function () use ($json) {
                        echo $json;
                    }, $name, ['Content-Type' => 'application/json']);
                }),

            Action::make('appleNewsPush')
                ->label('Push to Apple News')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('gray')
                ->visible(fn () => app(\App\Services\Apple\AppleNewsClient::class)->isConfigured())
                ->requiresConfirmation()
                ->modalHeading('Publish this article to Apple News?')
                ->modalDescription('Submits the ANF document to your configured Apple News channel. Apple may re-render layout to fit the News app.')
                ->action(function () {
                    $resp = app(\App\Services\Apple\AppleNewsClient::class)->post($this->record);
                    if (! $resp) {
                        Notification::make()->title('Apple News not configured')->danger()->send();
                        return;
                    }
                    Notification::make()
                        ->title($resp->successful() ? 'Pushed to Apple News' : 'Apple News push failed')
                        ->body(\Illuminate\Support\Str::limit($resp->body(), 600))
                        ->color($resp->successful() ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                }),

            Action::make('previewLink')
                ->label('Copy preview link')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->action(function () {
                    $token = \App\Http\Controllers\NewsController::previewTokenFor($this->record);
                    $url = route('news.details', ['slug' => $this->record->slug]).'?preview='.$token;

                    Notification::make()
                        ->title('Preview link (copy from below)')
                        ->body($url)
                        ->color('info')
                        ->persistent()
                        ->send();
                }),

            Action::make('viewRevisions')
                ->label('View revisions')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading('Revision history')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('5xl')
                ->modalContent(fn () => view('filament.resources.news.revisions', [
                    'activities' => \Spatie\Activitylog\Models\Activity::query()
                        ->where('subject_type', \App\Models\News::class)
                        ->where('subject_id', $this->record->id)
                        ->with('causer')
                        ->latest()
                        ->limit(50)
                        ->get(),
                ])),

            Action::make('createTranslation')
                ->label('Create translation')
                ->icon('heroicon-o-language')
                ->color('gray')
                ->schema([
                    Select::make('locale')
                        ->label('Target language')
                        ->options(function () {
                            $all = config('locales.supported', []);
                            $existing = News::query()
                                ->where('translation_group_id', $this->record->translation_group_id ?: $this->record->id)
                                ->pluck('locale')
                                ->filter()
                                ->all();
                            return collect($all)->reject(fn ($_, $code) => in_array($code, $existing, true))->all();
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    $source = $this->record;
                    $groupId = $source->translation_group_id ?: $source->id;

                    $copy = $source->replicate(['views', 'published_at', 'unpublished_at']);
                    $copy->locale = $data['locale'];
                    $copy->translation_group_id = $groupId;
                    $copy->slug = Str::slug($source->slug.'-'.$data['locale']);
                    $copy->title = '['.$data['locale'].'] '.$source->title;
                    $copy->editorial_status = News::STATUS_DRAFT;
                    $copy->save();

                    Notification::make()
                        ->title('Translation created')
                        ->body('Edit the new draft to add the translated content.')
                        ->success()
                        ->send();

                    return redirect(NewsResource::getUrl('edit', ['record' => $copy->id]));
                })
                ->visible(fn () => count(config('locales.supported', [])) > 1),
            DeleteAction::make(),
        ];
    }

    /**
     * Built-in content assistants. Each runs against the current record's
     * body and writes its result back to the article (as headline variants,
     * meta fields, or an inline preview) via a Filament notification + a
     * redirect so the updated form reloads with the new state.
     *
     * @return array<int, Action>
     */
    protected function aiActions(): array
    {
        return [
            Action::make('generateHeadlines')
                ->label('Generate A/B headlines')
                ->icon('heroicon-o-megaphone')
                ->action(fn () => $this->runGenerateHeadlines()),

            Action::make('writeSeoMeta')
                ->label('Write SEO meta')
                ->icon('heroicon-o-magnifying-glass')
                ->action(fn () => $this->runWriteSeoMeta()),

            Action::make('summarize')
                ->label('Generate TL;DR / excerpt')
                ->icon('heroicon-o-bars-3-bottom-left')
                ->requiresConfirmation()
                ->modalHeading('Replace the current excerpt?')
                ->modalDescription('The AI-generated summary will overwrite whatever is in the Excerpt field.')
                ->action(fn () => $this->runSummarize()),

            Action::make('copyedit')
                ->label('Copyedit the body')
                ->icon('heroicon-o-pencil-square')
                ->requiresConfirmation()
                ->modalHeading('Replace the article body with the AI copyedit?')
                ->modalDescription('Preserves voice and facts; fixes typos, grammar, and AP style.')
                ->action(fn () => $this->runCopyedit()),

            Action::make('copyeditStream')
                ->label('Copyedit (live stream)')
                ->icon('heroicon-o-bolt')
                ->modalHeading('Streaming copyedit')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.resources.news.copyedit-stream', [
                    'streamUrl' => route('ai.stream.copyedit', ['news' => $this->record->id]),
                    'applyUrl'  => route('ai.stream.copyedit.apply', ['news' => $this->record->id]),
                ])),

            Action::make('lint')
                ->label('Pre-publish lint')
                ->icon('heroicon-o-shield-check')
                ->action(fn () => $this->runLint()),

            Action::make('suggestCitations')
                ->label('Suggest source citations')
                ->icon('heroicon-o-link')
                ->action(fn () => $this->runCitations()),

            Action::make('translateArticle')
                ->label('Translate to a new sibling')
                ->icon('heroicon-o-globe-alt')
                ->schema([
                    Select::make('locale')
                        ->label('Target language')
                        ->options(function () {
                            $all = config('locales.supported', []);
                            $existing = News::query()
                                ->where('translation_group_id', $this->record->translation_group_id ?: $this->record->id)
                                ->pluck('locale')
                                ->filter()
                                ->all();
                            return collect($all)->reject(fn ($_, $code) => in_array($code, $existing, true))->all();
                        })
                        ->required(),
                ])
                ->action(fn (array $data) => $this->runTranslate($data['locale']))
                ->visible(fn () => count(config('locales.supported', [])) > 1),
        ];
    }

    /* -------- Assistant implementations -------- */

    protected function runGenerateHeadlines(): void
    {
        $body = $this->plainBody();
        if ($body === '') {
            $this->warn('Write some article body first — the assistants need material to work with.');
            return;
        }

        try {
            $resp = app(Assistant::class)->run(
                Assistant::KEY_HEADLINES,
                "Article title: {$this->record->title}\n\nExcerpt: {$this->record->excerpt}\n\nBody:\n{$body}",
            );
        } catch (AiException $e) {
            $this->errorNotify($e);
            return;
        }

        $variants = collect(preg_split("/\r?\n/", trim($resp->text)))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->take(5)
            ->values();

        if ($variants->isEmpty()) {
            Notification::make()->title('No variants generated')->warning()->send();
            return;
        }

        foreach ($variants as $i => $text) {
            NewsHeadline::create([
                'news_id'    => $this->record->id,
                'variant'    => $text,
                'is_default' => $i === 0,
                'impressions' => 0,
                'clicks'      => 0,
            ]);
        }

        Notification::make()
            ->title('Added '.$variants->count().' headline variants')
            ->body('Open the A/B headlines tab to review or edit.')
            ->success()
            ->send();

        $this->refreshFormData(['headlines']);
    }

    protected function runWriteSeoMeta(): void
    {
        $body = $this->plainBody();
        if ($body === '') { $this->warn('Write some article body first.'); return; }

        try {
            $resp = app(Assistant::class)->run(
                Assistant::KEY_SEO_META,
                "Title: {$this->record->title}\n\nBody:\n{$body}",
            );
        } catch (AiException $e) { $this->errorNotify($e); return; }

        $title = null; $description = null;
        foreach (preg_split("/\r?\n/", trim($resp->text)) as $line) {
            if (preg_match('/^Title:\s*(.+)$/i', $line, $m)) {
                $title = trim($m[1], " \t\"'");
            } elseif (preg_match('/^Description:\s*(.+)$/i', $line, $m)) {
                $description = trim($m[1], " \t\"'");
            }
        }

        if (! $title && ! $description) {
            Notification::make()->title('Could not parse SEO output')->body($resp->text)->warning()->send();
            return;
        }

        $this->record->forceFill(array_filter([
            'meta_title'       => $title,
            'meta_description' => $description,
        ]))->save();

        Notification::make()->title('SEO meta updated')->success()->send();
        $this->refreshFormData(['meta_title', 'meta_description']);
    }

    protected function runSummarize(): void
    {
        $body = $this->plainBody();
        if ($body === '') { $this->warn('Write some article body first.'); return; }

        try {
            $resp = app(Assistant::class)->run(
                Assistant::KEY_SUMMARY,
                "Title: {$this->record->title}\n\nBody:\n{$body}",
            );
        } catch (AiException $e) { $this->errorNotify($e); return; }

        $this->record->forceFill(['excerpt' => trim($resp->text)])->save();
        Notification::make()->title('Excerpt replaced with AI summary')->success()->send();
        $this->refreshFormData(['excerpt']);
    }

    protected function runCopyedit(): void
    {
        $body = (string) $this->record->content;
        if (trim(strip_tags($body)) === '') { $this->warn('No article body to edit.'); return; }

        try {
            $resp = app(Assistant::class)->run(
                Assistant::KEY_COPYEDIT,
                $body,
                ['max_tokens' => 4096],
            );
        } catch (AiException $e) { $this->errorNotify($e); return; }

        $this->record->forceFill(['content' => trim($resp->text)])->save();
        Notification::make()
            ->title('Body replaced with copyedit')
            ->body('Diff in the activity log.')
            ->success()
            ->send();
        $this->refreshFormData(['content']);
    }

    protected function runTranslate(string $locale): void
    {
        $body = (string) $this->record->content;
        if (trim(strip_tags($body)) === '') { $this->warn('No article body to translate.'); return; }

        $supported = config('locales.supported', []);
        $langLabel = $supported[$locale] ?? $locale;

        try {
            // Title + excerpt + body in one call so the translator keeps tone consistent.
            $resp = app(Assistant::class)->run(
                Assistant::KEY_TRANSLATE,
                "TARGET LANGUAGE: {$langLabel} ({$locale})\n\n".
                "---TITLE---\n{$this->record->title}\n\n".
                "---EXCERPT---\n{$this->record->excerpt}\n\n".
                "---BODY---\n{$body}",
                ['max_tokens' => 6144],
            );
        } catch (AiException $e) { $this->errorNotify($e); return; }

        // Parse the three sections out of the response. The translator may
        // return the same markers (ideal) or may omit them; fall back to
        // storing the whole thing in body if parsing fails.
        $text = $resp->text;
        $title = $this->section($text, 'TITLE') ?: ('['.$locale.'] '.$this->record->title);
        $excerpt = $this->section($text, 'EXCERPT') ?: $this->record->excerpt;
        $translatedBody = $this->section($text, 'BODY') ?: $text;

        $groupId = $this->record->translation_group_id ?: $this->record->id;
        $copy = $this->record->replicate(['views', 'published_at', 'unpublished_at']);
        $copy->locale = $locale;
        $copy->translation_group_id = $groupId;
        $copy->slug = Str::slug($this->record->slug.'-'.$locale);
        $copy->title = trim($title);
        $copy->excerpt = trim($excerpt);
        $copy->content = trim($translatedBody);
        $copy->editorial_status = News::STATUS_DRAFT;
        $copy->save();

        Notification::make()
            ->title("Translated draft created ({$langLabel})")
            ->body('Review in the new draft before publishing.')
            ->success()
            ->send();
    }

    protected function runCitations(): void
    {
        if ($this->plainBody() === '') { $this->warn('No article body to cite.'); return; }

        // Lazy-bootstrap the agent so this works on a fresh install
        // without a separate seed step.
        if (! \App\Models\AiAgent::where('key', 'editorial.citations')->exists()) {
            \App\Models\AiAgent::create([
                'key' => 'editorial.citations',
                'name' => 'Source citations agent',
                'description' => 'Reads an article and proposes citations for any factual claim that lacks a source.',
                'system_prompt' => "You are a fact-check / citations editor. Given an article id, your job is to add public source citations for the article's most concrete factual claims.\n\nWorkflow:\n  1. Call read_article on the supplied id.\n  2. Identify up to 5 specific claims that would benefit from a public citation.\n  3. For each claim where the article body or links provide a substantiating source, call add_source.\n  4. Return {\"final\": \"...\"} listing how many citations you added.\n\nDo NOT fabricate citation URLs. Skip any claim you can't substantiate from the article itself.",
                'tool_keys' => ['read_article', 'add_source'],
                'max_iterations' => 12,
                'temperature' => 0.15,
                'max_tokens_per_step' => 800,
                'is_active' => true,
            ]);
        }

        try {
            $run = app(\App\Services\Ai\Agent::class)->run(
                agentKey: 'editorial.citations',
                input: "Add source citations to article id={$this->record->id}.",
                userId: auth()->id(),
            );
        } catch (\Throwable $e) {
            Notification::make()->title('Citations agent failed')
                ->body(Str::limit($e->getMessage(), 400))->danger()->send();
            return;
        }

        if ($run->status !== \App\Models\AgentRun::STATUS_DONE) {
            Notification::make()->title('Citations agent failed')
                ->body(Str::limit((string) $run->error_message, 400))->danger()->send();
            return;
        }

        // Refresh sources relation so the "Citations" tab shows the new rows.
        $this->record->load('sources');
        $this->refreshFormData(['sources']);

        Notification::make()
            ->title('Citations updated')
            ->body((string) ($run->final_output ?: 'Open the Citations tab to review.'))
            ->success()
            ->persistent()
            ->send();
    }

    protected function runLint(): void
    {
        if ($this->plainBody() === '') { $this->warn('No article body to lint.'); return; }

        try {
            $findings = app(\App\Services\Ai\ArticleLinter::class)->lint($this->record);
        } catch (AiException $e) { $this->errorNotify($e); return; }

        if ($findings === null) {
            // Linter persisted a parser-error stub; mirror that in the session
            // so the live panel shows the raw output for debugging.
            $persisted = \App\Models\ArticleLintResult::where('news_id', $this->record->id)->first();
            session()->put("lint_findings_{$this->record->id}", $persisted?->findings ?? []);
            Notification::make()
                ->title('Lint produced unparseable output')
                ->body('See the findings panel below for the raw model output.')
                ->warning()
                ->send();
            return;
        }

        session()->put("lint_findings_{$this->record->id}", $findings);

        $count = count($findings);
        $criticals = collect($findings)->where('severity', 'critical')->count();

        if ($count === 0) {
            Notification::make()->title('Lint passed')->body('No findings — article looks clean.')->success()->send();
        } else {
            Notification::make()
                ->title("Lint produced {$count} findings"
                    .($criticals ? " ({$criticals} critical)" : ''))
                ->body('Scroll to the findings panel below the form.')
                ->color($criticals ? 'danger' : 'warning')
                ->send();
        }
    }

    /* -------- helpers -------- */

    protected function plainBody(): string
    {
        return trim(strip_tags((string) $this->record->content));
    }

    protected function section(string $text, string $marker): ?string
    {
        if (preg_match('/---'.$marker.'---\s*\n(.*?)(?=\n---|$)/s', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function errorNotify(AiException $e): void
    {
        Notification::make()
            ->title('Assistant failed')
            ->body(Str::limit($e->getMessage(), 500))
            ->danger()
            ->send();
    }

    protected function warn(string $msg): void
    {
        Notification::make()->title('Nothing to do')->body($msg)->warning()->send();
    }
}
