<?php

namespace App\Services\Paywall;

use App\Models\ArticleGift;
use App\Models\News;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Article-gift flow. Subscribers create single-use links that grant a
 * non-subscriber full access to a premium article.
 *
 *   issue(article, gifter, email)
 *       → mint a token, store sha256(token), email the link.
 *
 *   canIssue(gifter)
 *       → false when paywall is off, gifter isn't subscribed, or
 *         the per-month cap has been hit. Status array describes why.
 *
 *   redeem(article, token, request)
 *       → on success, mark redeemed_at, write a session flag so the
 *         recipient's subsequent reads stay entitled, return true.
 *
 *   isRedeemed(article, request)
 *       → cheap check used by Paywall::canRead to honor a redeemed
 *         gift on the current browser.
 */
class GiftService
{
    public const SESSION_KEY = 'gift_unlocks';

    public function isEnabled(): bool
    {
        $val = function_exists('getcong') ? getcong('paywall_gifts_enabled') : null;
        if ($val === null || $val === '') return (bool) config('paywall.gifts.enabled', true);
        return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
    }

    public function monthlyCap(): int
    {
        $val = function_exists('getcong') ? getcong('paywall_gifts_monthly_cap') : null;
        if ($val === null || $val === '' || ! preg_match('/^\d+$/', (string) $val)) {
            return (int) config('paywall.gifts.monthly_cap', 5);
        }
        return (int) $val;
    }

    public function ttlDays(): int
    {
        return (int) config('paywall.gifts.link_ttl_days', 14);
    }

    public function isSubscriber(User $user): bool
    {
        return method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber();
    }

    /**
     * @return array{
     *   can:          bool,
     *   reason?:      string,
     *   used:         int,
     *   cap:          int,
     *   remaining:    int,
     *   resets_at:    \Illuminate\Support\Carbon,
     * }
     */
    public function status(User $gifter): array
    {
        $cap   = $this->monthlyCap();
        $used  = $this->usedThisMonth($gifter);
        $can   = $this->isEnabled()
              && $this->isSubscriber($gifter)
              && ($cap === 0 || $used < $cap);

        $reason = null;
        if (! $this->isEnabled())                 $reason = 'gifts_disabled';
        elseif (! $this->isSubscriber($gifter))   $reason = 'not_subscribed';
        elseif ($cap > 0 && $used >= $cap)        $reason = 'monthly_cap_reached';

        return [
            'can'       => $can,
            'reason'    => $reason,
            'used'      => $used,
            'cap'       => $cap,
            'remaining' => $cap > 0 ? max(0, $cap - $used) : -1,
            'resets_at' => Carbon::now()->endOfMonth(),
        ];
    }

    public function canIssue(User $gifter): bool
    {
        return $this->status($gifter)['can'];
    }

    public function usedThisMonth(User $gifter): int
    {
        return (int) ArticleGift::where('gifter_user_id', $gifter->id)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }

    /**
     * Mint a fresh gift link and email it to the recipient. Returns
     * the plaintext token for inclusion in a confirmation flash; the
     * row stores only the hash.
     */
    public function issue(News $article, User $gifter, ?string $recipientEmail): ?string
    {
        if (! $this->canIssue($gifter)) return null;

        $plain = Str::random(48);
        $hash  = hash('sha256', $plain);

        ArticleGift::create([
            'news_id'         => $article->id,
            'gifter_user_id'  => $gifter->id,
            'recipient_email' => $recipientEmail ? mb_substr($recipientEmail, 0, 255) : null,
            'token_hash'      => $hash,
            'expires_at'      => Carbon::now()->addDays($this->ttlDays()),
        ]);

        $url = $this->giftUrl($article, $plain);

        if ($recipientEmail) {
            try {
                Mail::send('emails.article_gift', [
                    'gifterName' => $gifter->name,
                    'article'    => $article,
                    'giftUrl'    => $url,
                    'expiresIn'  => $this->ttlDays(),
                ], function ($message) use ($recipientEmail, $article) {
                    $message->to($recipientEmail)->subject(
                        'A gift article: '.\Illuminate\Support\Str::limit(strip_tags((string) $article->title), 80)
                    );
                });
            } catch (\Throwable $e) {
                Log::warning('Article gift email failed', [
                    'news_id' => $article->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $plain;
    }

    /**
     * Apply a `?gift={token}` redemption against an article. On
     * success, marks the row redeemed and writes a session flag so
     * the recipient's subsequent reads of this article stay
     * entitled. Returns true when redemption applied, false on any
     * mismatch / expired / already-redeemed case.
     */
    public function redeem(News $article, string $token, Request $request): bool
    {
        $token = trim($token);
        if (strlen($token) < 16) return false;

        $hash = hash('sha256', $token);

        $gift = ArticleGift::where('news_id', $article->id)
            ->where('token_hash', $hash)
            ->first();
        if (! $gift) return false;

        // Already-redeemed token still grants this browser entitlement
        // if its redemption was ours; otherwise it's spent.
        if ($gift->redeemed_at) {
            return $this->isRedeemed($article, $request);
        }

        if ($gift->expires_at && $gift->expires_at->isPast()) return false;

        $gift->forceFill([
            'redeemed_at' => Carbon::now(),
            'redeemed_ip' => $request->ip(),
        ])->save();

        $unlocks = (array) $request->session()->get(self::SESSION_KEY, []);
        $unlocks[(int) $article->id] = $gift->id;
        $request->session()->put(self::SESSION_KEY, $unlocks);

        return true;
    }

    public function isRedeemed(News $article, Request $request): bool
    {
        $unlocks = (array) $request->session()->get(self::SESSION_KEY, []);
        return isset($unlocks[(int) $article->id]);
    }

    public function giftUrl(News $article, string $plainToken): string
    {
        return route('news.details', ['slug' => $article->slug]).'?gift='.$plainToken;
    }
}
