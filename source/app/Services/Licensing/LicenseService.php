<?php

namespace App\Services\Licensing;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * License gate.
 *
 * Phase A scaffold: reads a license envelope from disk, parses the
 * payload, and exposes the contract the rest of the codebase relies
 * on (tier limits, feature flags, kind, force-powered-by, update
 * permission, host match). Phase B replaces the parser with a real
 * Ed25519 verify against an embedded public key — no contract
 * change above this class.
 *
 *   status()       cached snapshot
 *   isValid()      verify ok + within expiry + host matches
 *   tier()         resolved tier slug (or null)
 *   kind()         'production' | 'development' | 'trial' | null
 *   limit($key)    cap for admins / editors / authors / agents
 *                  (-1 = unlimited; null = no license, treat as 0)
 *   feature($flag) whether a given feature is unlocked
 *   canHidePoweredBy()  true when license permits (Team / Pro / Enterprise)
 *   isUpdateAllowed()   false past expiry — the only feature gated
 *                       immediately on license expiry
 *
 * IMPORTANT: there is intentionally NO env-based bypass. APP_ENV is
 * a string a pirate controls; treating it as a license escape hatch
 * would let anyone with the dist set APP_ENV=local and run unlicensed.
 * Developers run `php artisan license:install-dev` to mint a real
 * (kind=development) license, which is gitignored from source/ AND
 * stripped from build/ via scripts/build.sh.
 */
class LicenseService
{
    public const STORAGE_PATH = 'licensing/license.dat';

    private const CACHE_KEY = 'capstone.license.status.v1';
    private const CACHE_TTL = 300;

    public const KIND_PRODUCTION  = 'production';
    public const KIND_DEVELOPMENT = 'development';
    public const KIND_TRIAL       = 'trial';

    public function status(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->compute());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function compute(): array
    {
        $raw = $this->readEnvelope();
        if ($raw === null) {
            return $this->unlicensed('no license uploaded');
        }

        $payload = $this->parseEnvelope($raw);
        if ($payload === null) {
            return $this->invalid('license envelope unreadable');
        }

        // Phase B will run Ed25519 signature verification here. Until
        // then, the parser accepts any well-formed JSON. Production
        // builds without Phase B's verifier should NOT be shipped to
        // customers — the build script gates the dist artifact on
        // the verifier being compiled in.

        $kind = (string) ($payload['kind'] ?? self::KIND_PRODUCTION);
        $tierSlug = (string) ($payload['tier'] ?? '');
        $tierDefaults = (array) config("capstone.tiers.{$tierSlug}", []);
        $limits = (array) ($payload['limits'] ?? $tierDefaults);
        $features = (array) ($payload['features'] ?? ($tierDefaults['feature_flags'] ?? []));
        $force = (bool) ($payload['force_powered_by_footer'] ?? ($tierDefaults['force_powered_by_footer'] ?? false));
        $expiresAt = isset($payload['expires_at']) ? Carbon::parse((string) $payload['expires_at']) : null;
        $domains = (array) ($payload['domains'] ?? []);

        // Expiry check + grace window.
        $state = 'active';
        $now = Carbon::now();
        if ($expiresAt && $expiresAt->isPast()) {
            $graceDays = (int) config('capstone.expiry_grace_days', 14);
            if ($expiresAt->copy()->addDays($graceDays)->isFuture()) {
                $state = 'grace';
            } else {
                $state = 'expired';
            }
        }

        return [
            'state'         => $state,
            'kind'          => in_array($kind, [self::KIND_PRODUCTION, self::KIND_DEVELOPMENT, self::KIND_TRIAL], true)
                ? $kind : self::KIND_PRODUCTION,
            'tier_slug'     => $tierSlug ?: null,
            'tier_label'    => (string) ($tierDefaults['label'] ?? $tierSlug ?: 'Custom'),
            'limits'        => [
                'admins'  => (int) ($limits['admins']  ?? 0),
                'editors' => (int) ($limits['editors'] ?? 0),
                'authors' => (int) ($limits['authors'] ?? 0),
                'agents'  => (int) ($limits['agents']  ?? 0),
            ],
            'features'      => array_values(array_filter($features, 'is_string')),
            'force_powered' => $force,
            'expires_at'    => $expiresAt?->toIso8601String(),
            'domains'       => array_values(array_filter($domains, 'is_string')),
            'customer'      => (string) ($payload['customer'] ?? ''),
            'license_id'    => (string) ($payload['id'] ?? ''),
            'reason'        => null,
        ];
    }

    private function unlicensed(string $reason): array
    {
        return [
            'state'         => 'unlicensed',
            'kind'          => null,
            'tier_slug'     => null,
            'tier_label'    => 'Unlicensed',
            'limits'        => ['admins' => 0, 'editors' => 0, 'authors' => 0, 'agents' => 0],
            'features'      => [],
            'force_powered' => true,
            'expires_at'    => null,
            'domains'       => [],
            'customer'      => '',
            'license_id'    => '',
            'reason'        => $reason,
        ];
    }

    private function invalid(string $reason): array
    {
        return [
            'state'         => 'invalid',
            'kind'          => null,
            'tier_slug'     => null,
            'tier_label'    => 'Invalid license',
            'limits'        => ['admins' => 0, 'editors' => 0, 'authors' => 0, 'agents' => 0],
            'features'      => [],
            'force_powered' => true,
            'expires_at'    => null,
            'domains'       => [],
            'customer'      => '',
            'license_id'    => '',
            'reason'        => $reason,
        ];
    }

    private function readEnvelope(): ?string
    {
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::STORAGE_PATH)) return null;
            $raw = (string) $disk->get(self::STORAGE_PATH);
            return $raw !== '' ? $raw : null;
        } catch (\Throwable $e) {
            Log::warning('LicenseService: envelope read failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse the envelope. Phase A: accept either raw JSON or a
     * "<base64-json>.<base64-sig>" wire format; the signature half is
     * not verified yet. Phase B's verifier replaces this method body
     * with a real Ed25519 check before unmarshal.
     */
    private function parseEnvelope(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Wire format: "<b64payload>.<b64sig>"
        if (str_contains($raw, '.')) {
            [$b64payload] = explode('.', $raw, 2);
            $json = $this->base64UrlDecode($b64payload);
        } else {
            $json = $raw;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function base64UrlDecode(string $s): string
    {
        $pad = (4 - strlen($s) % 4) % 4;
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', $pad));
    }

    public function isValid(): bool
    {
        $s = $this->status();
        return in_array($s['state'], ['active', 'grace'], true);
    }

    public function kind(): ?string
    {
        return $this->status()['kind'];
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
        return in_array($s['state'], ['active', 'grace'], true);
    }

    public function isUpdateAllowed(): bool
    {
        return $this->status()['state'] === 'active';
    }

    /**
     * Verify the request's host against the license's domain list,
     * with proxy-rewrite hardening to defeat the localhost-front
     * pirate pattern.
     *
     * The pirate scenario we're closing: install CapstoneNMS on a
     * VPS, set APP_URL=localhost (or strip it), reverse-proxy
     * acme.com → 127.0.0.1, ship one licensed install to many
     * domains. Defenses:
     *   1. localhost / loopback only honored when APP_ENV is not
     *      production AND no X-Forwarded-* headers are present.
     *   2. The Host header must agree with parse_url(config('app.url'))
     *      — a pirate proxying acme.com → 127.0.0.1 leaves the
     *      backend Host as either "acme.com" (in which case the
     *      domain match runs against acme.com, which isn't licensed)
     *      or "localhost" (caught by rule 1).
     *   3. Domains in the license payload are matched literally or
     *      via "*.example.com" subdomain wildcard.
     */
    public function hostMatches(Request $request): bool
    {
        $s = $this->status();
        if (! in_array($s['state'], ['active', 'grace'], true)) return false;

        $host = strtolower($request->getHost());
        if ($host === '') return false;

        // Cross-check Host vs APP_URL host. A mismatch means either
        // a misconfiguration or a proxied install — refuse either way.
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '' && $appHost !== $host) {
            // Allow loopback-shaped APP_URLs to pair with real hosts
            // because some infra fronts every request with a public
            // hostname even when the backend's APP_URL points at
            // localhost. We let the loopback exemption (below) catch
            // those cases instead of double-failing.
            if (! $this->isLoopbackHost($appHost)) {
                return false;
            }
        }

        // Always-allowed hosts (loopback, *.test, *.local) — only
        // valid in non-production environments AND only when no
        // proxy chain is present.
        if ($this->matchesAlwaysAllowed($host)) {
            if (app()->environment('production')) return false;
            if ($request->headers->has('X-Forwarded-For')) return false;
            if ($request->headers->has('X-Forwarded-Host')) return false;
            return true;
        }

        // License-payload domain list (literal + *.subdomain wildcards).
        foreach ($s['domains'] as $pattern) {
            if ($this->hostMatchesPattern($host, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    private function matchesAlwaysAllowed(string $host): bool
    {
        $list = (array) config('capstone.always_allowed_hosts', []);
        foreach ($list as $pattern) {
            if ($this->hostMatchesPattern($host, strtolower($pattern))) return true;
        }
        return false;
    }

    private function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1', ''], true);
    }

    private function hostMatchesPattern(string $host, string $pattern): bool
    {
        if ($pattern === '') return false;
        if ($pattern === $host) return true;
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // ".example.com"
            return str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.');
        }
        return false;
    }
}
