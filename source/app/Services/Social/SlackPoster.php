<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Slack incoming-webhook poster. Settings:
 *
 *   social_slack_enabled          toggle
 *   slack_webhook_url             https://hooks.slack.com/services/T.../B.../...
 *
 * Single text + link block per article. No reply or thread handling —
 * the webhook only creates new messages by design.
 */
class SlackPoster extends AbstractPoster
{
    public function key(): string   { return 'slack'; }
    public function label(): string { return 'Slack (webhook)'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_slack_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        $url = $this->setting('slack_webhook_url');
        return $url !== null && str_starts_with($url, 'https://hooks.slack.com/');
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);

        $response = Http::timeout(15)
            ->acceptJson()
            ->post((string) $this->setting('slack_webhook_url'), [
                'text'   => "*{$title}*\n{$url}",
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*<{$url}|{$title}>*"]],
                ],
                'unfurl_links' => true,
                'unfurl_media' => true,
            ]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $this->markSent($rowId, null, ['status' => $response->status()]);
        return ['ok' => true, 'post_url' => null];
    }
}
