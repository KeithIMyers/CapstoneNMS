<?php

namespace App\Services\Tts;

use App\Models\News;
use App\Services\Ai\TtsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single-voice narration of the article body. Distinct from the
 * podcast generator (which produces a two-host conversational
 * format) — this one reads the article straight, like a
 * traditional read-aloud accessibility feature.
 *
 * The article body comes from News::renderedBody(), so block-edited
 * articles get rendered first (through the HTML output) before the
 * stripper turns it into TTS-ready plain text. The text is split
 * into provider-friendly chunks (~3500 chars each) and the MP3
 * bytes are concatenated end-to-end.
 *
 * Settings:
 *   article_tts_voice    default voice key (defaults to "alloy")
 */
class ArticleTtsGenerator
{
    /** Conservative chunk to stay under typical TTS provider 4096-char limits. */
    private const CHUNK_CHARS = 3500;

    /**
     * @return array{ok:bool, message?:string, path?:string,
     *               duration_chars?:int, voice?:string}
     */
    public function generate(News $article): array
    {
        $body = trim($article->renderedBody());
        if ($body === '') {
            return ['ok' => false, 'message' => 'Article has no body to narrate.'];
        }

        // The renderer outputs HTML; turn it into TTS-ready prose.
        // Drop tags, decode entities, collapse whitespace.
        $text = trim(preg_replace('/\s+/u', ' ',
            html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ) ?? '');

        // Lead with the title so the audio is self-introducing.
        $title = trim(strip_tags(stripslashes((string) $article->title)));
        if ($title !== '') {
            $text = $title.'. '.$text;
        }

        if ($text === '') {
            return ['ok' => false, 'message' => 'Body had no readable text after stripping markup.'];
        }

        $voice = trim((string) (function_exists('getcong') ? getcong('article_tts_voice') : '')) ?: 'alloy';
        $tts   = app(TtsClient::class);

        $chunks   = $this->chunkText($text, self::CHUNK_CHARS);
        $combined = '';
        $chars    = 0;
        foreach ($chunks as $i => $chunk) {
            try {
                $audio = $tts->speak(
                    text: $chunk,
                    voice: $voice,
                    purpose: 'tts.article',
                );
            } catch (\Throwable $e) {
                Log::warning('Article TTS chunk failed', [
                    'news_id' => $article->id,
                    'chunk'   => $i,
                    'error'   => $e->getMessage(),
                ]);
                // Bail outright rather than ship a half-narrated audio
                // — listeners would notice the truncation.
                return [
                    'ok'      => false,
                    'message' => 'TTS failed at chunk '.($i + 1).': '.Str::limit($e->getMessage(), 200),
                ];
            }
            $combined .= $audio->bytes;
            $chars += mb_strlen($chunk);
        }

        if ($combined === '') {
            return ['ok' => false, 'message' => 'No audio bytes produced.'];
        }

        $path = sprintf('tts/%d-%s.mp3', $article->id, Str::lower(Str::random(8)));
        Storage::disk('public')->put($path, $combined);

        // Best-effort cleanup of the previous version so we don't
        // leak old audio when an editor regenerates.
        if ($article->tts_audio_path && $article->tts_audio_path !== $path) {
            try {
                Storage::disk('public')->delete($article->tts_audio_path);
            } catch (\Throwable $e) { /* ignore */ }
        }

        $article->forceFill([
            'tts_audio_path'   => $path,
            'tts_generated_at' => now(),
            'tts_voice'        => $voice,
            'tts_chars'        => $chars,
        ])->saveQuietly();

        return [
            'ok'             => true,
            'message'        => sprintf(
                'Read-aloud generated (%s chars · %s · %s KB).',
                number_format($chars),
                $voice,
                number_format(round(strlen($combined) / 1024)),
            ),
            'path'           => $path,
            'duration_chars' => $chars,
            'voice'          => $voice,
        ];
    }

    /**
     * Split into <= $max char chunks at sentence boundaries when
     * possible, falling through to whitespace and finally hard
     * char-cap so we never exceed the provider's per-call limit.
     *
     * @return array<int, string>
     */
    private function chunkText(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) return [$text];

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $chunks   = [];
        $current  = '';

        foreach ($sentences as $s) {
            // Lone sentence longer than $max: hard-split mid-word.
            if (mb_strlen($s) > $max) {
                if ($current !== '') { $chunks[] = $current; $current = ''; }
                while (mb_strlen($s) > $max) {
                    $chunks[] = mb_substr($s, 0, $max);
                    $s = mb_substr($s, $max);
                }
                $current = $s;
                continue;
            }
            $candidate = $current === '' ? $s : ($current.' '.$s);
            if (mb_strlen($candidate) > $max) {
                $chunks[] = $current;
                $current = $s;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }
}
