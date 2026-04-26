<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Services\Ai\Agent;
use App\Services\Paywall\AskQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public "Ask the newsroom" surface. Visitors submit a plain-English
 * question; the editorial.research agent searches site coverage and
 * returns a sourced answer with citations.
 *
 * Four layers of abuse / cost protection sit in front of the agent:
 *   1. Tier-aware monthly cap (AskQuota): anonymous / registered /
 *      subscriber, configurable per tier in Site Settings → Ask
 *      paywall. The tier ladder lets readers convert into subscribers
 *      via a nudge instead of a hard wall on the first try.
 *   2. IP rate limit:  10 questions/hour, 30/day (RateLimiter facade).
 *   3. Question dedup: identical questions resolve from a 24h cache so
 *      a refresh-spammer can't burn an LLM call per click. Cached
 *      hits do NOT consume tier quota.
 *   4. AI provider budget: same per-day caps as the rest of the app.
 *
 * The page renders even when no provider is configured — it shows a
 * disabled state explaining the feature isn't enabled, so the route
 * never 500s on a fresh install.
 */
class AskController extends Controller
{
    private const AGENT_KEY = 'editorial.research';
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly AskQuota $quota) {}

    public function show(Request $request)
    {
        $available = AiProvider::active()->exists()
            && AiAgent::where('key', self::AGENT_KEY)->where('is_active', true)->exists();

        return view('pages.ask', $this->basePayload($request, [
            'available' => $available,
        ]));
    }

    public function ask(Request $request)
    {
        $request->validate([
            'question' => 'required|string|min:5|max:1000',
        ]);

        $question  = trim((string) $request->input('question'));
        $available = AiProvider::active()->exists()
            && AiAgent::where('key', self::AGENT_KEY)->where('is_active', true)->exists();

        $payload = $this->basePayload($request, [
            'available' => $available,
            'question'  => $question,
        ]);

        if (! $available) {
            $payload['error'] = 'The Ask feature isn\'t configured on this site yet.';
            return view('pages.ask', $payload);
        }

        // 1. Tier-aware monthly cap. Out-of-quota gets a paywall view
        //    rather than an error flash so the upgrade CTA stays prominent.
        if (! $this->quota->allows($request)) {
            $payload['paywalled'] = true;
            return view('pages.ask', $payload);
        }

        // 2. IP rate limit: 10/hour, 30/day. Two separate buckets so a
        //    burst doesn't lock out a casual user for the rest of the day.
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts("ask:hour:{$ip}", 10)) {
            $payload['error'] = 'You\'ve asked too many questions in the last hour. Try again later.';
            return view('pages.ask', $payload);
        }
        if (RateLimiter::tooManyAttempts("ask:day:{$ip}", 30)) {
            $payload['error'] = 'Daily question limit reached for this network. Try again tomorrow.';
            return view('pages.ask', $payload);
        }

        // 3. Cache identical questions for a day. Stops refresh-spam
        //    burning dollars and gives repeat visitors fast answers.
        //    Cached hits do NOT consume quota — only fresh LLM calls do.
        $cacheKey = 'ask:'.hash('sha256', mb_strtolower($question));
        $cached = Cache::store('file')->get($cacheKey);
        if ($cached) {
            $payload = array_merge($payload, $cached, [
                'cached' => true,
                // Re-pull quota AFTER the cache merge so the meter
                // shown alongside a cached answer reflects current
                // remaining count, not the snapshot from when the
                // answer was originally cached.
                'quota'  => $this->quota->status($request),
            ]);
            return view('pages.ask', $payload);
        }

        // Hit the IP rate limiters now (before the LLM call) so a
        // flapping provider doesn't allow extra retries through.
        RateLimiter::hit("ask:hour:{$ip}", 3600);
        RateLimiter::hit("ask:day:{$ip}", 86400);

        try {
            $run = app(Agent::class)->run(
                agentKey: self::AGENT_KEY,
                input: $question,
                userId: $request->user()?->id,
            );
        } catch (\Throwable $e) {
            $payload['error'] = 'Couldn\'t reach the research agent right now. Try again in a minute.';
            \Illuminate\Support\Facades\Log::warning('AskController agent threw', [
                'error' => $e->getMessage(),
            ]);
            return view('pages.ask', $payload);
        }

        if ($run->status !== AgentRun::STATUS_DONE || empty($run->final_output)) {
            $payload['error'] = 'The research agent couldn\'t produce an answer for that question.';
            return view('pages.ask', $payload);
        }

        // Quota tick: only on a successful, non-cached run. Authed
        // users are counted via the ai_requests rows the agent already
        // wrote; anonymous visitors get an explicit cache increment.
        $this->quota->consume($request);

        // Pull article ids the agent quoted from the transcript so we
        // can show real article links beside the answer rather than
        // relying on the model to format them perfectly.
        $sources = $this->extractSourceArticleIds($run);

        $payload['answer']  = $run->final_output;
        $payload['sources'] = $sources;

        // Refresh quota status so the post-answer view reflects the new count.
        $payload['quota'] = $this->quota->status($request);

        Cache::store('file')->put($cacheKey, [
            'answer'  => $payload['answer'],
            'sources' => $payload['sources'],
        ], self::CACHE_TTL_SECONDS);

        return view('pages.ask', $payload);
    }

    /**
     * Shared default payload — keeps `show()` and `ask()` in sync as
     * we add view bindings.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function basePayload(Request $request, array $overrides = []): array
    {
        return array_merge([
            'available' => false,
            'question'  => '',
            'answer'    => null,
            'sources'   => [],
            'cached'    => false,
            'error'     => null,
            'paywalled' => false,
            'quota'     => $this->quota->status($request),
        ], $overrides);
    }

    /**
     * Walk the agent transcript for read_article tool calls and resolve
     * each id back to a (title, slug) pair so we can render real links
     * beside the answer.
     *
     * @return array<int, array{id:int, title:string, slug:string}>
     */
    private function extractSourceArticleIds(AgentRun $run): array
    {
        $ids = [];
        foreach ((array) $run->transcript as $msg) {
            if (! isset($msg['tool_call'])) continue;
            $tc = $msg['tool_call'];
            if (($tc['name'] ?? '') !== 'read_article') continue;
            $id = (int) ($tc['arguments']['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        if (empty($ids)) return [];

        return \App\Models\News::published()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'title', 'slug'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'title' => (string) $a->title, 'slug' => (string) $a->slug])
            ->values()
            ->all();
    }
}
