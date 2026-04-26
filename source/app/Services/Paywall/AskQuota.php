<?php

namespace App\Services\Paywall;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tier-aware monthly cap for the public "Ask the newsroom" feature.
 *
 * Tier ladder (each one inherits the previous tier's allowance):
 *   anonymous   — no auth session
 *   registered  — logged in, no active Stripe subscription
 *   subscriber  — logged in, active subscription
 *
 * Counting:
 *   - Authenticated users: COUNT(ai_requests) WHERE user_id=X AND
 *     purpose LIKE 'agent.editorial.research%' AND created_at >=
 *     startOfMonth — uses the existing audit table; no extra schema.
 *   - Anonymous users:    a small Cache::increment counter keyed by
 *     sha256(ip)+YYYY-MM with a 32-day TTL. Survives the month
 *     rollover by virtue of the YYYY-MM in the key.
 *
 * Settings precedence: `getcong('paywall_ask_*')` runtime overrides
 * trump `config('paywall.ask.*')` env defaults.
 */
class AskQuota
{
    public const TIER_ANONYMOUS  = 'anonymous';
    public const TIER_REGISTERED = 'registered';
    public const TIER_SUBSCRIBER = 'subscriber';

    /**
     * @return array{
     *   tier:       string,
     *   enabled:    bool,
     *   limit:      int,           // 0 = blocked, -1 = unlimited
     *   used:       int,
     *   remaining:  int,           // -1 = unlimited
     *   resets_at:  \Illuminate\Support\Carbon,
     * }
     */
    public function status(Request $request): array
    {
        $tier    = $this->tierFor($request);
        $enabled = $this->isEnabled();
        $limit   = $this->limitFor($tier);
        $used    = $this->usedThisMonth($request);
        $remain  = $limit === -1 ? -1 : max(0, $limit - $used);

        return [
            'tier'      => $tier,
            'enabled'   => $enabled,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $remain,
            'resets_at' => Carbon::now()->endOfMonth(),
        ];
    }

    /**
     * Whether the visitor still has quota left this month. When the
     * paywall is disabled we always allow — the controller's IP rate
     * limit and dedup cache still keep things in check.
     */
    public function allows(Request $request): bool
    {
        if (! $this->isEnabled()) return true;
        $s = $this->status($request);
        return $s['remaining'] === -1 || $s['remaining'] > 0;
    }

    /**
     * Record one consumed question. For anonymous visitors we increment
     * a dedicated cache counter; for authenticated users the AskController
     * already triggers an `ai_requests` row via the agent runner, which
     * is what `usedThisMonth()` counts — so authed users need no
     * separate ledger here.
     */
    public function consume(Request $request): void
    {
        if (! $this->isEnabled()) return;
        if ($request->user()) return;

        $key = $this->anonCacheKey($request);
        // 32 days is enough to span any single calendar month; the
        // YYYY-MM segment in the key naturally rolls over so old
        // counters become unreachable on the 1st of the next month.
        try {
            if (! Cache::store('file')->has($key)) {
                Cache::store('file')->put($key, 1, now()->addDays(32));
                return;
            }
            Cache::store('file')->increment($key);
        } catch (\Throwable $e) {
            // Cache store unreachable (disk full, perms): swallow so
            // we don't 500 the user. allows() will fail-closed on the
            // next request because usedThisMonth() returns the limit
            // when reads fail.
            \Illuminate\Support\Facades\Log::warning('AskQuota::consume cache write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the visitor's tier. Subscriber check goes through Cashier's
     * Billable trait so it stays accurate as users upgrade / cancel.
     */
    public function tierFor(Request $request): string
    {
        $user = $request->user();
        if (! $user) return self::TIER_ANONYMOUS;

        if (method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber()) {
            return self::TIER_SUBSCRIBER;
        }
        return self::TIER_REGISTERED;
    }

    public function limitFor(string $tier): int
    {
        return match ($tier) {
            self::TIER_SUBSCRIBER => $this->setting(
                'paywall_ask_subscriber_monthly',
                (int) config('paywall.ask.subscriber_monthly_quota', 100),
            ),
            self::TIER_REGISTERED => $this->setting(
                'paywall_ask_registered_monthly',
                (int) config('paywall.ask.registered_monthly_quota', 10),
            ),
            default => $this->setting(
                'paywall_ask_anonymous_monthly',
                (int) config('paywall.ask.anonymous_monthly_quota', 3),
            ),
        };
    }

    public function isEnabled(): bool
    {
        $val = function_exists('getcong') ? getcong('paywall_ask_enabled') : null;
        if ($val === null || $val === '') {
            return (bool) config('paywall.ask.enabled', false);
        }
        return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
    }

    public function usedThisMonth(Request $request): int
    {
        $user = $request->user();
        if ($user instanceof User) {
            return (int) AiRequest::query()
                ->where('user_id', $user->id)
                ->where('purpose', 'like', 'agent.editorial.research%')
                ->where('created_at', '>=', Carbon::now()->startOfMonth())
                ->count();
        }
        // Anonymous: read from cache. If the cache store is unreachable
        // (disk full, perms) we fail CLOSED — return a sentinel large
        // enough that `remaining` resolves to 0 and the paywall fires.
        // Better to block a few legitimate visitors than to flip the
        // counter to "unlimited" because the disk filled up.
        try {
            return (int) (Cache::store('file')->get($this->anonCacheKey($request), 0));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AskQuota::usedThisMonth cache read failed', [
                'error' => $e->getMessage(),
            ]);
            return PHP_INT_MAX;
        }
    }

    /**
     * Resolve a per-tier quota override from settings, falling back to
     * the env-driven default. Non-numeric values that aren't the
     * documented `-1` / `unlimited` sentinels are treated as junk and
     * the env default is used — better to silently fall back than to
     * coerce `"abc"` to 0 and block all visitors.
     */
    private function setting(string $key, int $envDefault): int
    {
        $val = function_exists('getcong') ? getcong($key) : null;
        if ($val === null || $val === '') return $envDefault;
        $s = strtolower(trim((string) $val));
        if ($s === '-1' || $s === 'unlimited') return -1;
        if (! preg_match('/^-?\d+$/', $s)) {
            \Illuminate\Support\Facades\Log::warning('AskQuota: non-numeric setting ignored', [
                'key' => $key, 'value' => $val,
            ]);
            return $envDefault;
        }
        return (int) $s;
    }

    private function anonCacheKey(Request $request): string
    {
        $ipHash = sha1((string) $request->ip());
        $month  = Carbon::now()->format('Y-m');
        return "ask:anon_count:{$month}:{$ipHash}";
    }
}
