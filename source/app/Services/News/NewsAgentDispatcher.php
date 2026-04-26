<?php

namespace App\Services\News;

use App\Models\AgentStory;
use App\Models\AiProvider;
use App\Models\News;
use App\Models\NewsAgent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\ImageGenClient;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Run one queued story through a research → draft → image → publish
 * pipeline as the configured news agent.
 *
 * The runner walks the same JSON-in-prompt / native-tool-calling loop
 * the editorial Agent service uses, but with three differences:
 *
 *   1. The system prompt is assembled from the agent's persona —
 *      writing style + knowledgebase + target word count + style hints
 *      — rather than from a stored AiAgent row.
 *
 *   2. The provider/model is the agent's pinned override (when set),
 *      falling back to the install-wide default.
 *
 *   3. After the model calls create_draft, we generate a hero image
 *      via ImageGenClient (with the agent's pinned image provider, or
 *      the install-wide default), save it to public storage, and
 *      attach it to the article. The article's user_id is rewritten
 *      from the dispatching admin to the agent's persona so the
 *      public byline shows the right name and photo.
 *
 * Returns ['ok' => bool, 'message' => string, 'news_id' => ?int].
 */
class NewsAgentDispatcher
{
    private const MAX_ITERATIONS = 14;
    private const TOOL_KEYS = ['semantic_search', 'search_articles', 'read_article', 'fetch_url', 'create_draft'];

    public function __construct(
        private readonly AiClient $client,
        private readonly ImageGenClient $imageClient,
        private readonly ToolRegistry $registry,
    ) {}

    public function dispatchStory(NewsAgent $agent, AgentStory $story): array
    {
        $story->forceFill([
            'status'        => AgentStory::STATUS_DRAFTING,
            'attempt_count' => $story->attempt_count + 1,
        ])->save();

        try {
            $newsId = $this->runDraft($agent, $story);
        } catch (\Throwable $e) {
            $story->forceFill([
                'status'     => AgentStory::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 1500),
            ])->save();
            Log::warning('News agent draft failed', [
                'agent_id' => $agent->id,
                'story_id' => $story->id,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'Draft failed: '.$e->getMessage()];
        }

        if (! $newsId) {
            $story->forceFill([
                'status'     => AgentStory::STATUS_FAILED,
                'last_error' => 'Agent finished without calling create_draft.',
            ])->save();
            return ['ok' => false, 'message' => 'Agent finished without producing an article.'];
        }

        // Re-attribute the article to the agent's persona regardless of
        // who initiated the dispatch. The persona's User row carries
        // the public byline + photo + bio.
        $news = News::find($newsId);
        if ($news && $agent->user_id) {
            $news->user_id = $agent->user_id;
            $news->saveQuietly();
        }

        $story->forceFill([
            'status'  => AgentStory::STATUS_DRAFTED,
            'news_id' => $newsId,
        ])->save();

        // Image generation. Best-effort: we don't fail the story if the
        // image step throws — editors can re-attempt or upload a hero
        // image manually. The text article is always the higher-value
        // artifact.
        try {
            $this->generateAndAttachImage($agent, $news);
        } catch (\Throwable $e) {
            Log::warning('Hero image generation failed', [
                'news_id' => $newsId,
                'error' => $e->getMessage(),
            ]);
            $story->forceFill(['last_error' => 'Image gen: '.mb_substr($e->getMessage(), 0, 1000)])->save();
        }

        $story->forceFill([
            'status'       => AgentStory::STATUS_PUBLISHED,
            'completed_at' => now(),
        ])->save();

        return ['ok' => true, 'message' => "Drafted article id={$newsId}.", 'news_id' => $newsId];
    }

    /* -------- internals -------- */

    private function runDraft(NewsAgent $agent, AgentStory $story): ?int
    {
        $tools = $this->registry->only(self::TOOL_KEYS);
        $provider = $agent->ai_provider_id
            ? AiProvider::find($agent->ai_provider_id)
            : null;

        $resolvedProvider = $provider ?: AiProvider::defaultProvider();
        $driver = $resolvedProvider ? $this->client->driverFor($resolvedProvider) : null;
        $useNative = $driver && $driver->supportsTools();
        $toolDefs = $useNative ? $this->normalizedToolDefs($tools) : null;

        $messages = [
            ['role' => 'system', 'content' => $this->systemPromptFor($agent, $useNative)],
            ['role' => 'user',   'content' => $this->userMessageFor($story, $agent)],
        ];

        $iterations = 0;
        $newsId = null;

        while ($iterations < self::MAX_ITERATIONS) {
            $iterations++;

            $response = $this->client->complete(
                messages: $messages,
                provider: $provider,
                purpose: 'news_agent.'.$agent->id.'.draft',
                options: array_filter([
                    'temperature' => $agent->temperature,
                    'max_tokens'  => $agent->max_tokens,
                    'model'       => $agent->ai_model,
                    'tools'       => $useNative ? $toolDefs : null,
                ]),
            );

            // Native tool-call path.
            if ($useNative && ! empty($response->toolCalls)) {
                $messages[] = ['role' => 'assistant', 'content' => $response->text, 'tool_calls' => $response->toolCalls];
                foreach ($response->toolCalls as $tc) {
                    $name = (string) ($tc['name'] ?? '');
                    $args = (array) ($tc['arguments'] ?? []);
                    $obs  = $this->dispatchTool($tools, $name, $args);
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string) ($tc['id'] ?? ''),
                        'content'      => $obs,
                    ];
                    if ($name === 'create_draft' && preg_match('/id=(\d+)/', $obs, $m)) {
                        $newsId = (int) $m[1];
                    }
                }
                if ($newsId) return $newsId;
                continue;
            }

            // Native path with no tool_calls = final answer.
            if ($useNative) {
                if (preg_match('/id=(\d+)/', $response->text, $m)) {
                    return (int) $m[1];
                }
                return null;
            }

            // JSON-in-prompt fallback path.
            $messages[] = ['role' => 'assistant', 'content' => $response->text];
            $parsed = $this->parseJson($response->text);
            if ($parsed === null) {
                if (preg_match('/id=(\d+)/', $response->text, $m)) return (int) $m[1];
                return null;
            }
            if (array_key_exists('final', $parsed)) {
                if (preg_match('/id=(\d+)/', (string) $parsed['final'], $m)) return (int) $m[1];
                return null;
            }
            if (isset($parsed['tool_call'])) {
                $name = (string) ($parsed['tool_call']['name'] ?? '');
                $args = (array)  ($parsed['tool_call']['arguments'] ?? []);
                $obs  = $this->dispatchTool($tools, $name, $args);
                $messages[] = ['role' => 'user', 'content' => "OBSERVATION (tool={$name}):\n{$obs}"];
                if ($name === 'create_draft' && preg_match('/id=(\d+)/', $obs, $m)) {
                    return (int) $m[1];
                }
                continue;
            }

            // Unknown shape — nudge.
            $messages[] = ['role' => 'user', 'content' => 'Respond with a JSON tool_call or {"final": "..."} object.'];
        }

        return $newsId;
    }

    private function generateAndAttachImage(NewsAgent $agent, ?News $news): void
    {
        if (! $news) return;

        $imageProvider = $agent->image_provider_id
            ? AiProvider::find($agent->image_provider_id)
            : null;
        if (! $imageProvider) {
            $defaultId = function_exists('getcong') ? getcong('default_image_provider_id') : null;
            if ($defaultId) {
                $imageProvider = AiProvider::find($defaultId);
            }
        }
        if (! $imageProvider) {
            $imageProvider = AiProvider::defaultImageProvider();
        }
        if (! $imageProvider) {
            return; // no image provider configured anywhere
        }

        $prompt = $this->imagePromptFor($agent, $news);
        $resp = $this->imageClient->generate(
            prompt: $prompt,
            provider: $imageProvider,
            size: '1024x1024',
            model: $agent->image_model,
            purpose: 'news_agent.'.$agent->id.'.image',
        );

        $filename = sprintf('news/%d-%s.%s',
            $news->id,
            \Illuminate\Support\Str::random(8),
            $resp->extension(),
        );
        Storage::disk('public')->put($filename, $resp->bytes);

        $news->image = $filename;
        if (empty($news->image_alt)) {
            $news->image_alt = mb_substr((string) $news->title, 0, 200);
        }
        $news->saveQuietly();
    }

    private function systemPromptFor(NewsAgent $agent, bool $useNative): string
    {
        $persona = trim((string) $agent->writing_style);
        $kb      = trim((string) $agent->knowledgebase);
        $userName = $agent->user?->name ?? 'this agent';
        $words = (int) ($agent->target_word_count ?: 600);

        $protocol = $useNative
            ? "When you have enough material, call create_draft with title + excerpt + body + category_slug. After create_draft succeeds, respond with plain prose containing the result string the tool returned (e.g. \"OK | id=42 | …\")."
            : <<<'P'
RESPONSE FORMAT — every response is a single JSON object.
Two valid shapes:
  Tool call:    {"tool_call": {"name": "<key>", "arguments": {...}}}
  Final answer: {"final": "<the OK | id=… string from create_draft>"}
No prose outside the JSON object.
P;

        return implode("\n\n", array_filter([
            "You are {$userName}, a virtual newsroom contributor.",
            $persona !== '' ? "VOICE / STYLE:\n".$persona : null,
            $kb !== '' ? "KNOWLEDGEBASE (treat as established context):\n".$kb : null,
            "WORKFLOW:\n".
            "1. Use semantic_search and search_articles to learn what we've already covered on the topic.\n".
            "2. For 1-3 of the most relevant hits, call read_article and quote specifics.\n".
            "3. If the editor supplied SOURCE URLS, fetch each one with fetch_url.\n".
            "4. Compose the article in your voice. Target ~{$words} words. Inverted pyramid, AP style, inline attribution.\n".
            "5. Call create_draft with title (under 90 chars), excerpt (one sentence), body, and category_slug from the brief.\n".
            "6. Return the OK string from create_draft so the dispatcher can attach the hero image.",
            $protocol,
        ]));
    }

    private function userMessageFor(AgentStory $story, NewsAgent $agent): string
    {
        $catSlug = $story->category_id ? optional(\App\Models\Category::find($story->category_id))->slug : null;
        $urls = is_array($story->source_urls) ? $story->source_urls : [];

        $lines = [];
        $lines[] = 'TOPIC: '.$story->topic;
        if (! empty($story->brief)) $lines[] = "EDITOR BRIEF:\n".$story->brief;
        if ($catSlug)               $lines[] = 'CATEGORY SLUG: '.$catSlug;
        if (! empty($urls))         $lines[] = "SOURCE URLS:\n  - ".implode("\n  - ", $urls);
        $lines[] = 'TARGET LENGTH: ~'.$agent->target_word_count.' words';

        return implode("\n\n", $lines);
    }

    private function imagePromptFor(NewsAgent $agent, News $news): string
    {
        $body = trim(strip_tags((string) $news->content));
        $body = mb_substr($body, 0, 600);
        $hint = trim((string) $agent->image_style_hint);

        $prompt = "Editorial news photo for an article titled \"{$news->title}\".\n";
        if ($news->excerpt) $prompt .= 'Excerpt: '.mb_substr((string) $news->excerpt, 0, 300)."\n";
        $prompt .= "Lead paragraph context:\n".$body."\n\n";
        $prompt .= 'No text or watermarks in the image. No real public figures unless described in context.';
        if ($hint !== '') {
            $prompt .= "\n\nStyle: ".$hint;
        }
        return $prompt;
    }

    private function dispatchTool(array $tools, string $name, array $args): string
    {
        if ($name === '') return 'ERROR: tool_call.name required';
        if (! isset($tools[$name])) {
            return 'ERROR: tool not available: '.$name;
        }
        try {
            return $tools[$name]->execute($args);
        } catch (\Throwable $e) {
            return 'ERROR: tool threw: '.\Illuminate\Support\Str::limit($e->getMessage(), 240);
        }
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?: $text;
        if (str_starts_with($text, '{')) {
            $d = json_decode($text, true);
            if (is_array($d)) return $d;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);
            if (is_array($d)) return $d;
        }
        return null;
    }

    private function normalizedToolDefs(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $properties = [];
            $required   = [];
            foreach ($tool->arguments() as $argName => $desc) {
                $properties[$argName] = ['type' => 'string', 'description' => (string) $desc];
                if (! str_contains(strtolower((string) $desc), 'optional')) {
                    $required[] = $argName;
                }
            }
            $out[] = [
                'name'        => $tool->key(),
                'description' => $tool->description(),
                'parameters'  => [
                    'type'                 => 'object',
                    'properties'           => empty($properties) ? new \stdClass() : $properties,
                    'required'             => $required,
                    'additionalProperties' => false,
                ],
            ];
        }
        return $out;
    }
}
