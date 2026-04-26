<?php

namespace App\Services\Ai;

/**
 * Normalized TTS response. Carries raw audio bytes + mime so the
 * podcast generator can write the file straight to disk.
 */
final class TtsResponse
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType, // audio/mpeg | audio/wav | audio/opus
        public readonly ?string $model = null,
        public readonly ?string $voice = null,
        public readonly int $bytesIn = 0,
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'audio/mpeg' => 'mp3',
            'audio/wav'  => 'wav',
            'audio/opus' => 'opus',
            'audio/aac'  => 'aac',
            'audio/flac' => 'flac',
            default      => 'mp3',
        };
    }
}
