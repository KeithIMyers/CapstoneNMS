<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Telegram bot poster. Settings:
 *
 *   social_telegram_enabled       toggle
 *   telegram_bot_token            from @BotFather
 *   telegram_chat_id              channel/group/user id (e.g. -1001234567890 or @newschannel)
 *
 * Uses sendMessage with HTML parse mode and disabled web-page preview
 * disabled = false (we want the rich card under the link).
 */
class TelegramPoster extends AbstractPoster
{
    public function key(): string   { return 'telegram'; }
    public function label(): string { return 'Telegram'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_telegram_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->setting('telegram_bot_token') !== null
            && $this->setting('telegram_chat_id') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);

        $token  = (string) $this->setting('telegram_bot_token');
        $chatId = (string) $this->setting('telegram_chat_id');

        // HTML parse mode requires escaping &, <, >.
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = "<b>{$safeTitle}</b>\n\n<a href=\"{$safeUrl}\">{$safeUrl}</a>";

        $response = Http::timeout(15)
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => false,
            ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $msgId = $body['result']['message_id'] ?? null;
        // Public-channel permalink only works for @username chats with
        // a public username; gracefully skip for private numeric ids.
        $postUrl = null;
        if ($msgId && str_starts_with($chatId, '@')) {
            $postUrl = 'https://t.me/'.ltrim($chatId, '@')."/{$msgId}";
        }
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }
}
