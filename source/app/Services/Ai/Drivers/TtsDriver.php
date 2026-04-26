<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\TtsResponse;

/**
 * Contract for text-to-speech adapters.
 *
 *   speak(text, voice, format)
 *
 * voice: provider-specific name (e.g. OpenAI "nova" / "onyx").
 * format: "mp3" | "wav" | "opus" — drivers map to provider-specific
 * codec strings.
 */
interface TtsDriver
{
    public function __construct(AiProvider $provider);

    public function speak(string $text, string $voice, string $format = 'mp3', ?string $model = null): TtsResponse;
}
