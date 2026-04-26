<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Discord webhook poster. Settings:
 *
 *   social_discord_enabled        toggle
 *   discord_webhook_url           https://discord.com/api/webhooks/{id}/{token}
 *
 * Sends an embed with title + URL + excerpt. Discord embeds get
 * rich previews automatically.
 */
class DiscordPoster extends AbstractPoster
{
    public function key(): string   { return 'discord'; }
    public function label(): string { return 'Discord (webhook)'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_discord_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        $url = $this->setting('discord_webhook_url');
        return $url !== null && (
            str_starts_with($url, 'https://discord.com/api/webhooks/')
            || str_starts_with($url, 'https://discordapp.com/api/webhooks/')
        );
    }

    protected function send(News $article, int $rowId): array
    {
        $url     = $this->articleUrl($article);
        $title   = $this->articleTitle($article);
        $excerpt = trim(strip_tags(stripslashes((string) $article->excerpt)));
        if (mb_strlen($excerpt) > 280) $excerpt = mb_substr($excerpt, 0, 279).'…';

        $response = Http::timeout(15)
            ->acceptJson()
            // ?wait=true so Discord returns the message id; lets us
            // capture a permalink for the audit row.
            ->post(((string) $this->setting('discord_webhook_url')).'?wait=true', [
                'embeds' => [[
                    'title'       => mb_substr($title, 0, 256),
                    'url'         => $url,
                    'description' => $excerpt ?: null,
                    'color'       => 0x0EA5E9,
                ]],
            ]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $postUrl = isset($body['channel_id'], $body['id'])
            ? "https://discord.com/channels/@me/{$body['channel_id']}/{$body['id']}"
            : null;
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }
}
