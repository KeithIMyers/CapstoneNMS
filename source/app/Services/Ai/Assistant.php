<?php

namespace App\Services\Ai;

use App\Models\AiPrompt;
use App\Models\AiProvider;

/**
 * Thin wrapper around AiClient that loads a named prompt, assembles a
 * two-message chat (system + user), and returns the response. Assistants
 * in the admin UI call this rather than AiClient directly so all tunable
 * knobs (temperature, max tokens, provider pinning) live in ai_prompts.
 *
 * Fallback prompts for each built-in assistant are shipped inline here so
 * the feature works immediately after migrate without a seeder run; the
 * first call for each key persists the fallback so it's editable going
 * forward.
 */
class Assistant
{
    public const KEY_HEADLINES     = 'article.headlines';
    public const KEY_SEO_META      = 'article.seo_meta';
    public const KEY_SUMMARY       = 'article.summary';
    public const KEY_COPYEDIT      = 'article.copyedit';
    public const KEY_TRANSLATE     = 'article.translate';
    public const KEY_ALT_TEXT      = 'article.alt_text';
    public const KEY_LINT          = 'article.lint';
    public const KEY_PODCAST_SCRIPT = 'article.podcast_script';
    public const KEY_MODERATE_CMT  = 'comment.moderate';

    /** Default prompts shipped with the app; editable after first use. */
    public const DEFAULT_PROMPTS = [
        self::KEY_HEADLINES => <<<'P'
You are a veteran newsroom headline editor. Given an article excerpt,
produce 5 headline variants optimized for click-through without being
misleading. Keep each under 90 characters. Return ONLY the headlines,
one per line, no numbering, no quotes, no commentary.
P,
        self::KEY_SEO_META => <<<'P'
You write SEO metadata for news articles. Given the article body, return
exactly two lines:
  Title: <meta title, 50-60 characters, no trailing site name>
  Description: <meta description, 150-160 characters, one sentence>
No commentary, no quotes, no extra lines.
P,
        self::KEY_SUMMARY => <<<'P'
You are a news editor writing a tight TL;DR summary. Given the article,
return 2-3 sentences (under 400 characters total) summarizing the key
facts and why readers should care. No hedging, no quotes, no lists.
P,
        self::KEY_COPYEDIT => <<<'P'
You are a newsroom copy editor. Given the article body, return a
lightly edited version that preserves the author's voice while fixing:
typos, subject/verb agreement, AP-style dates and numbers, awkward
phrasing, and redundant words. Do NOT change the article's facts or
opinions. Return only the edited body — no commentary, no diff markup.
P,
        self::KEY_TRANSLATE => <<<'P'
You are a professional news translator. Translate the article below into
the target language provided. Preserve the journalistic voice and the
paragraph structure. Translate proper nouns idiomatically (names stay
as-is). Do not add or remove facts. Return only the translated text.
P,
        self::KEY_ALT_TEXT => <<<'P'
You write concise alt text for news photos. Describe what is visually
present in under 125 characters. No "image of" or "photo of"; no
subjective commentary. One sentence, no trailing period if it fits
more naturally without one.
P,
        self::KEY_LINT => <<<'P'
You are a managing editor reviewing a news article before publication.
Read the article and produce a JSON array of findings. Each finding is
an object with these fields:

  severity   "critical" | "warn" | "info"
  category   "factual" | "tone" | "structure" | "attribution" | "seo" | "legal"
  message    one short sentence (under 160 chars) describing the issue
  fix        one sentence suggesting a concrete fix (optional, may be omitted)

Return ONLY the JSON array — no Markdown, no commentary, no surrounding
prose. If the article has no issues worth surfacing, return an empty
array []. Be specific and actionable; do not nitpick style preferences.
P,
        self::KEY_PODCAST_SCRIPT => <<<'P'
You write conversational podcast scripts — two hosts having a smart,
natural-sounding "Deep Dive" discussion about an article. The audience
should leave understanding the story, not just hear a summary.

Hosts:
  HOST_A — warm, curious, asks the framing questions, plays the
           "explain it to me" role.
  HOST_B — knowledgeable, contextual, brings the analysis and
           specific facts from the article.

Output a JSON array of dialog lines, each with "speaker" ("A" or "B")
and "text" (the line). Keep individual lines short (one to two
sentences) so audio playback feels natural with line breaks.

Total dialog: 8-14 lines, ~120-200 words combined. Open with HOST_A
introducing the topic; close with HOST_B summarizing the takeaway and
a brief sign-off. No filler "um"/"uh"; no script directions like
[laughs]. Don't say "according to this article" — speak as if both
hosts have already read it.

Return ONLY the JSON array. No Markdown fences. No prose. Example:
[
  {"speaker":"A","text":"Today we're talking about the new energy bill that just cleared the Senate."},
  {"speaker":"B","text":"Right, and the headline number is fifty billion dollars over ten years for grid modernization."},
  ...
]
P,
        self::KEY_MODERATE_CMT => <<<'P'
You are a comment moderator for a US news site. Given a user-submitted
comment, classify it as one of:
  ALLOW    — on-topic, civil, no policy violation
  REVIEW   — borderline (rudeness, off-topic, low quality)
  REJECT   — hate speech, harassment, doxxing, illegal content, spam
Return ONLY the single word ALLOW, REVIEW, or REJECT on the first line,
optionally followed by one short sentence of reasoning on the next line.
P,
    ];

    public function __construct(private readonly AiClient $client) {}

    /**
     * Run an assistant by prompt key. $userContent is the variable input
     * (article body, comment text, etc.); the system prompt comes from
     * the prompt row.
     *
     * @param array<string, mixed> $extraOptions optional overrides passed
     *        to the driver (e.g., ['temperature' => 0.2])
     */
    public function run(string $key, string $userContent, array $extraOptions = []): AiResponse
    {
        $fallback = self::DEFAULT_PROMPTS[$key] ?? 'You are a helpful assistant.';
        $prompt = AiPrompt::forKey($key, $fallback);

        $provider = $prompt->provider_id
            ? AiProvider::find($prompt->provider_id)
            : null;

        $options = array_merge([
            'temperature' => $prompt->temperature,
            'max_tokens'  => $prompt->max_tokens,
        ], $extraOptions);

        if ($prompt->model_override) {
            $options['model'] = $prompt->model_override;
        }

        return $this->client->complete(
            messages: [
                ['role' => 'system', 'content' => $prompt->system_prompt],
                ['role' => 'user',   'content' => $userContent],
            ],
            provider: $provider,
            purpose: $key,
            options: $options,
        );
    }
}
