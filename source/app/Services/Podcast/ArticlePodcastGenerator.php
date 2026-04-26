<?php

namespace App\Services\Podcast;

use App\Models\News;
use App\Services\Ai\Assistant;
use App\Services\Ai\TtsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generate a NotebookLM-style two-host podcast for an article.
 *
 *   1. Run the article.podcast_script prompt on the article body to
 *      produce a JSON array of {speaker, text} dialog lines.
 *   2. For each line, call TtsClient with a voice mapped from the
 *      speaker letter — HOST_A = nova, HOST_B = onyx by default. Per-
 *      install voices can be overridden via settings (podcast_voice_a,
 *      podcast_voice_b).
 *   3. Append every chunk's MP3 bytes into one file.
 *      MP3 frames are self-contained, so simple byte concat is enough
 *      to produce a player-friendly track. Browser <audio> elements
 *      handle the result gracefully.
 *   4. Persist storage path + script + generated_at on the News row
 *      so the public detail view can render an inline player.
 *
 * Returns:
 *   ['ok' => bool, 'message' => str, 'path' => ?str, 'duration_chars' => int]
 */
class ArticlePodcastGenerator
{
    public function generate(News $article): array
    {
        $body = trim(strip_tags(stripslashes((string) $article->content)));
        if ($body === '') {
            return ['ok' => false, 'message' => 'Article has no body to script.'];
        }

        // 1. Script.
        try {
            $resp = app(Assistant::class)->run(
                Assistant::KEY_PODCAST_SCRIPT,
                "Article title: {$article->title}\n\nExcerpt: {$article->excerpt}\n\nBody:\n{$body}",
                ['max_tokens' => 1500, 'temperature' => 0.6],
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Script generation failed: '.$e->getMessage()];
        }

        $raw = trim($resp->text);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?: $raw;
        $script = json_decode($raw, true);
        if (! is_array($script) || empty($script)) {
            return ['ok' => false, 'message' => 'Could not parse podcast script.'];
        }

        // Normalize: drop entries without speaker+text, cap to 30 lines.
        $lines = [];
        foreach (array_slice($script, 0, 30) as $row) {
            if (! is_array($row)) continue;
            $sp = strtoupper((string) ($row['speaker'] ?? ''));
            $tx = trim((string) ($row['text'] ?? ''));
            if (! in_array($sp, ['A', 'B'], true) || $tx === '') continue;
            $lines[] = ['speaker' => $sp, 'text' => $tx];
        }
        if (empty($lines)) {
            return ['ok' => false, 'message' => 'Script parsed but contained no usable lines.'];
        }

        // 2. + 3. TTS each line, append MP3 bytes.
        $voiceA = trim((string) (function_exists('getcong') ? getcong('podcast_voice_a') : '')) ?: 'nova';
        $voiceB = trim((string) (function_exists('getcong') ? getcong('podcast_voice_b') : '')) ?: 'onyx';
        $tts    = app(TtsClient::class);

        $combined = '';
        $chars = 0;
        foreach ($lines as $i => $line) {
            $voice = $line['speaker'] === 'A' ? $voiceA : $voiceB;
            try {
                $audio = $tts->speak(
                    text: $line['text'],
                    voice: $voice,
                    purpose: 'tts.podcast',
                );
            } catch (\Throwable $e) {
                Log::warning('Podcast TTS line failed', [
                    'news_id' => $article->id,
                    'line'    => $i,
                    'error'   => $e->getMessage(),
                ]);
                // Skip this line and move on; one TTS failure shouldn't
                // kill the whole episode.
                continue;
            }
            $combined .= $audio->bytes;
            $chars += mb_strlen($line['text']);
        }

        if ($combined === '') {
            return ['ok' => false, 'message' => 'No audio was produced — every TTS line failed.'];
        }

        // 4. Save + stamp the article.
        $path = sprintf('podcasts/%d-%s.mp3', $article->id, Str::lower(Str::random(8)));
        Storage::disk('public')->put($path, $combined);

        $article->forceFill([
            'podcast_audio_path'   => $path,
            'podcast_script'       => $lines,
            'podcast_generated_at' => now(),
        ])->saveQuietly();

        return [
            'ok'              => true,
            'message'         => sprintf(
                'Podcast generated (%d lines · %s chars · %s KB)',
                count($lines),
                number_format($chars),
                number_format(round(strlen($combined) / 1024)),
            ),
            'path'            => $path,
            'duration_chars'  => $chars,
        ];
    }
}
