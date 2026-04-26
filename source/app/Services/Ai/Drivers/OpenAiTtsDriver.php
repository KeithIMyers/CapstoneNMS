<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\TtsResponse;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Text-to-Speech adapter. Talks to /v1/audio/speech.
 * Supported voices: alloy, ash, ballad, coral, echo, fable, nova,
 * onyx, sage, shimmer, verse — names map directly to the provider's
 * voice parameter.
 *
 * Default model is gpt-4o-mini-tts (low cost, decent quality). The
 * provider's tts_model field overrides; per-call model param wins
 * over both.
 */
class OpenAiTtsDriver implements TtsDriver
{
    public function speak(string $text, string $voice, string $format = 'mp3', ?string $model = null): TtsResponse
    {
        $model ??= $this->provider->tts_model ?: 'gpt-4o-mini-tts';
        $base   = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');

        $response = Http::withToken($this->provider->api_key ?? '')
            ->timeout(120)
            ->withHeaders(['Accept' => 'audio/mpeg, audio/wav, audio/opus, */*'])
            ->post($base.'/audio/speech', [
                'model'           => $model,
                'voice'           => $voice,
                'input'           => $text,
                'response_format' => $format,
            ]);

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI TTS failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }

        return new TtsResponse(
            bytes: $response->body(),
            mimeType: $response->header('Content-Type') ?: ('audio/'.$this->mimeFor($format)),
            model: $model,
            voice: $voice,
            bytesIn: strlen($text),
        );
    }

    public function __construct(private readonly AiProvider $provider) {}

    private function mimeFor(string $format): string
    {
        return match ($format) {
            'mp3' => 'mpeg',
            'wav' => 'wav',
            'opus' => 'opus',
            'aac' => 'aac',
            'flac' => 'flac',
            default => 'mpeg',
        };
    }
}
