<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\ImageGenResponse;

/**
 * Contract every image-generation adapter implements. Mirrors the chat
 * + embedding driver interfaces so ImageGenClient can dispatch
 * uniformly.
 *
 * generate() takes a prompt + size hint and returns binary image
 * bytes. size is a "WIDTHxHEIGHT" string (e.g., "1024x1024"); each
 * driver maps it to whatever its provider accepts.
 */
interface ImageGenDriver
{
    public function __construct(AiProvider $provider);

    public function generate(string $prompt, string $size = '1024x1024', ?string $model = null): ImageGenResponse;
}
