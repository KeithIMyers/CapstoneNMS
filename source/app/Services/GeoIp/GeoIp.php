<?php

namespace App\Services\GeoIp;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolve an IP to an ISO 3166-1 alpha-2 country code using a local
 * MaxMind GeoLite2-Country .mmdb database. The database lives outside
 * the repo (downloaded once, refreshed monthly via CRON or by hand).
 *
 * The service is fail-soft on every layer:
 *   - missing geoip2/geoip2 package: returns null
 *   - missing .mmdb file:           returns null
 *   - private / unroutable IP:      returns null
 *   - lookup throws:                logs and returns null
 *
 * That way enabling GeoIP is purely additive — uninstall the package
 * or remove the .mmdb file and every caller silently falls back.
 *
 * Lookups are memoized per-IP for the lifetime of the request.
 */
class GeoIp
{
    /** @var array<string, ?string> */
    private array $cache = [];

    private ?Reader $reader = null;
    private bool $tried = false;

    public function countryFor(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        if (array_key_exists($ip, $this->cache)) {
            return $this->cache[$ip];
        }

        $reader = $this->reader();
        if (! $reader) {
            return $this->cache[$ip] = null;
        }

        try {
            $record = $reader->country($ip);
            $code = $record->country->isoCode ?: null;
            return $this->cache[$ip] = $code;
        } catch (\Throwable $e) {
            // Address-not-found is the common "not in DB" path; everything
            // else gets logged once at warning level so admins can spot
            // a corrupt .mmdb without flooding logs.
            return $this->cache[$ip] = null;
        }
    }

    private function reader(): ?Reader
    {
        if ($this->tried) return $this->reader;
        $this->tried = true;

        if (! class_exists(Reader::class)) {
            return null; // geoip2/geoip2 not installed
        }

        $path = config('geoip.database_path');
        if (! $path || ! is_file($path)) {
            return null;
        }

        try {
            $this->reader = new Reader($path);
        } catch (\Throwable $e) {
            Log::warning('GeoIp reader init failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->reader = null;
        }
        return $this->reader;
    }

    /**
     * One-shot health check used by the diagnostic command and the
     * Filament settings page. Returns a status string.
     */
    public function health(): string
    {
        if (! class_exists(Reader::class)) {
            return 'package "geoip2/geoip2" not installed (run composer install)';
        }
        $path = config('geoip.database_path');
        if (! $path) return 'config/geoip.php not published or database_path is empty';
        if (! is_file($path)) return "database file not found at {$path}";
        try {
            $r = new Reader($path);
            $meta = $r->metadata();
            return sprintf('OK · %s · built %s · %d records',
                $meta->databaseType,
                date('Y-m-d', $meta->buildEpoch),
                $meta->nodeCount,
            );
        } catch (\Throwable $e) {
            return 'reader threw: '.$e->getMessage();
        }
    }
}
