<?php

namespace App\Services\Paywall;

use App\Models\News;

/**
 * Decides whether the current viewer is entitled to the full body of
 * a premium article. Pure business rules; no Stripe calls — those
 * happen on the subscribe / webhook side via Cashier.
 *
 *   canRead(article)  -> bool
 *   previewBody(body) -> string  (first ~2 paragraphs, capped)
 *
 * Editors / admins / the article's own author are always entitled so
 * QA and authoring don't get gated by their own paywall.
 */
class Paywall
{
    /** Approximate preview length in characters. */
    public const PREVIEW_CHARS = 600;

    public function canRead(News $article): bool
    {
        if (! $article->is_premium) return true;

        // License gate: paywall is a Pro / Enterprise feature. On
        // tiers without it the article is served full-bodied — the
        // editorial team can still flag premium content but the
        // gate doesn't enforce. The Site Settings → Paywall tab is
        // also hidden in the License page UI for those tiers.
        if (! app(\App\Services\Licensing\LicenseService::class)->feature('paywall')) {
            return true;
        }

        $user = auth()->user();
        if ($user) {
            // Internal users always see full content.
            if (method_exists($user, 'isAuthor') && $user->isAuthor()) return true;
            if ($user->id === $article->user_id) return true;

            // Active Cashier subscription on any configured tier slug
            // bypasses the paywall. User::isPaidSubscriber walks every
            // active tier so adding a new tier doesn't require touching
            // this gate.
            if (method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber()) return true;

            // Team-subscription members get the same entitlement as
            // the team owner. The service walks every active seat
            // assigned to this user and confirms the owner's Cashier
            // subscription is current before granting access.
            if (app(TeamSubscriptionService::class)->isTeamMember($user)) return true;
        }

        // Gift-article entitlement on this browser? A subscriber may
        // have minted a single-use link that the recipient already
        // redeemed; the GiftService stashes a session flag so re-
        // reads from the same browser stay open without burning more
        // gift quota.
        if (request()) {
            $gift = app(GiftService::class);
            if ($gift->isRedeemed($article, request())) return true;
        }

        // Pay-per-article: an authenticated user may have bought
        // standalone access to this premium piece. The active scope
        // also enforces the optional TTL on the purchase.
        if ($user) {
            $hasPurchase = \App\Models\ArticlePurchase::query()
                ->where('user_id', $user->id)
                ->where('news_id', $article->id)
                ->active()
                ->exists();
            if ($hasPurchase) return true;
        }

        // Anonymous + non-subscribed authenticated visitors fall through
        // to the metered gate. When the meter has remaining quota, this
        // article counts as one of their free reads and they get the
        // full body. The counter is bumped via meterTick() below — the
        // controller calls it after canRead() returns true so re-reads
        // of the same article in one window don't double-charge.
        return $this->meterRemaining($article) > 0;
    }

    /**
     * How many free premium reads remain in the visitor's current
     * window. Tracked via a small JSON cookie so it survives session
     * resets but isn't a privacy nightmare. The cookie holds an array
     * of {id, ts} reads in the last meter_window_days; pruning happens
     * inline so it stays tiny.
     *
     * @return int
     */
    public function meterRemaining(News $article): int
    {
        $limit = (int) config('paywall.meter_limit', 5);
        if ($limit <= 0) return 0;

        $reads = $this->loadMeterReads();
        // If this same article is already counted, the visitor has
        // unlimited re-reads of the same piece — only first reads burn
        // quota.
        $articleAlreadyMetered = collect($reads)->contains(fn ($r) => (int) $r['id'] === (int) $article->id);
        $consumed = count($reads);
        $remaining = max(0, $limit - $consumed);
        // First-read of THIS article: a free read still has remaining>0.
        // Re-read of an already-counted article: still allowed regardless.
        if ($articleAlreadyMetered) return max(1, $remaining);
        return $remaining;
    }

    /**
     * Tick the meter for this article. Called from NewsController only
     * AFTER canRead() returned true and the request actually rendered
     * the full body — so refreshes of an already-counted article don't
     * double-count.
     */
    public function meterTick(News $article): void
    {
        if (! $article->is_premium) return;
        if (auth()->check()) return; // logged-in users get bypass / subscriber check; no meter

        $limit = (int) config('paywall.meter_limit', 5);
        if ($limit <= 0) return;

        $reads = $this->loadMeterReads();
        if (collect($reads)->contains(fn ($r) => (int) $r['id'] === (int) $article->id)) {
            return;
        }
        $reads[] = ['id' => (int) $article->id, 'ts' => time()];
        $this->saveMeterReads($reads);
    }

    /** @return array<int, array{id:int, ts:int}> */
    private function loadMeterReads(): array
    {
        $raw = (string) request()->cookie('usnt_meter', '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) return [];

        // Prune entries outside the rolling window.
        $cutoff = time() - ((int) config('paywall.meter_window_days', 30)) * 86400;
        return array_values(array_filter(
            $decoded,
            fn ($r) => is_array($r) && isset($r['id'], $r['ts']) && $r['ts'] >= $cutoff,
        ));
    }

    private function saveMeterReads(array $reads): void
    {
        // Cookie set on the next response. Lifetime matches window so
        // it expires naturally with the meter.
        $minutes = (int) config('paywall.meter_window_days', 30) * 24 * 60;
        cookie()->queue('usnt_meter', json_encode(array_values($reads)), $minutes);
    }

    /**
     * Truncate an article body to a preview length without breaking
     * mid-sentence. Strips HTML for safety; the caller is expected to
     * wrap the result in its own markup.
     */
    public function previewBody(?string $body): string
    {
        $plain = trim(strip_tags((string) $body));
        if ($plain === '') return '';

        if (mb_strlen($plain) <= self::PREVIEW_CHARS) {
            return $plain;
        }

        $cut = mb_substr($plain, 0, self::PREVIEW_CHARS);
        // Walk back to the last sentence boundary so the preview doesn't
        // end on a half-word.
        if (preg_match('/^(.*[.!?])[^.!?]*$/su', $cut, $m)) {
            $cut = $m[1];
        }
        return $cut.'…';
    }
}
