<?php

namespace App\Services\Licensing;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * License gate. Phase A scaffold only — full Ed25519 verification
 * lands in Phase B. The shape this class exposes today drives the
 * rest of the codebase (Powered-by footer, tier-cap checks on user
 * creation, admin License page) so the wiring is in place when the
 * crypto comes online.
 *
 *   status()       cached snapshot of the current license payload
 *   isValid()      signature ok + within expiry + domain matches
 *   tier()         resolved tier slug (or 'unlicensed')
 *   limit($key)    cap for admins / editors / authors / agents
 *                  (-1 = unlimited; null = no license, treat as 0)
 *   feature($flag) whether a given feature is unlocked
 *   canHidePoweredBy()  true on Team / Pro / Enterprise
 *   isUpdateAllowed()   false past expiry (the only feature gated
 *                       hard by the renewal subscription)
 *
 * The runtime never phones home. Validation is purely local: signed
 * payload + embedded public key + domain comparison + clock check.
 */
class LicenseService
{
    /** Where the customer's uploaded license file lives on disk. */
    public const STORAGE_PATH = 'licensing/license.dat';

    /** A 5-minute cache so the verify path doesn't disk-read on every request. */
    private const CACHE_KEY = 'capstone.license.status.v1';
    private const CACHE_TTL = 300;

    /**
     * When no license is present and APP_ENV is local/development,
     * fall back to this tier so a developer can run the app without
     * minting a license. Production installs treat absence as
     * "unlicensed" and lock the panel.
     */
    public const DEV_FALLBACK_TIER = 'enterprise';

    public function status(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->compute());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Phase A: compute returns either the dev-fallback tier or an
     * "unlicensed" stub. Phase B replaces this with: read the file,
     * verify Ed25519, parse payload, cross-check domain + expiry.
     */
    private function compute(): array
    {
        if (app()->environment(['local', 'development', 'testing'])) {
            return $this->devFallback();
        }
        return $this->unlicensed();
    }

    private function devFallback(): array
    {
        $tier = (string) config("capstone.tiers." . self::DEV_FALLBACK_TIER . '.label', 'Enterprise');
        $defaults = (array) config('capstone.tiers.' . self::DEV_FALLBACK_TIER, []);
        return [
            'state'         => 'dev',
            'tier_slug'     => self::DEV_FALLBACK_TIER,
            'tier_label'    => $tier,
            'limits'        => [
                'admins'  => $defaults['admins']  ?? -1,
                'editors' => $defaults['editors'] ?? -1,
                'authors' => $defaults['authors'] ?? -1,
                'agents'  => $defaults['agents']  ?? -1,
            ],
            'features'      => $defaults['feature_flags'] ?? [],
            'force_powered' => false,
            'expires_at'    => null,
            'domains'       => [],
            'reason'        => 'dev-fallback (APP_ENV='.app()->environment().')',
        ];
    }

    private function unlicensed(): array
    {
        return [
            'state'         => 'unlicensed',
            'tier_slug'     => null,
            'tier_label'    => 'Unlicensed',
            'limits'        => ['admins' => 0, 'editors' => 0, 'authors' => 0, 'agents' => 0],
            'features'      => [],
            'force_powered' => true,
            'expires_at'    => null,
            'domains'       => [],
            'reason'        => 'no license uploaded',
        ];
    }

    public function isValid(): bool
    {
        $s = $this->status();
        return in_array($s['state'], ['dev', 'active', 'grace'], true);
    }

    public function tier(): ?string
    {
        return $this->status()['tier_slug'];
    }

    public function limit(string $key): ?int
    {
        $s = $this->status();
        if (! isset($s['limits'][$key])) return null;
        return (int) $s['limits'][$key];
    }

    public function feature(string $flag): bool
    {
        return in_array($flag, $this->status()['features'] ?? [], true);
    }

    public function canHidePoweredBy(): bool
    {
        $s = $this->status();
        if (! empty($s['force_powered'])) return false;
        return in_array($s['state'], ['dev', 'active', 'grace'], true);
    }

    public function isUpdateAllowed(): bool
    {
        $s = $this->status();
        // Updates are the one feature gated immediately on expiry —
        // grace period buys time on everything else but renewal pays
        // for the right to pull new releases.
        return $s['state'] === 'active' || $s['state'] === 'dev';
    }

    /**
     * Cross-check an HTTP request's effective host against the
     * license's allowed domains. Phase B implements the proxy
     * defenses (APP_ENV, X-Forwarded-* presence, app.url match).
     */
    public function hostMatches(Request $request): bool
    {
        // Phase A: dev fallback always matches; unlicensed never does.
        $s = $this->status();
        return in_array($s['state'], ['dev', 'active', 'grace'], true);
    }
}
