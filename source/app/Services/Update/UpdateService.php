<?php

namespace App\Services\Update;

use App\Services\Licensing\LicenseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CapstoneNMS update applier.
 *
 *   currentVersion()       what config/capstone.php declares
 *   fetchManifest()        signed manifest pull from the configured CDN
 *   availableUpdate()      manifest.latest vs currentVersion (or null)
 *   applyDownload($entry)  download + verify + extract + migrate + clear
 *   applyUploaded($zip,$sig)
 *                          same pipeline minus the download — for
 *                          air-gapped customers who upload manually
 *
 * The "atomic swap" target on shared hosting is unrealistic; instead
 * we snapshot the install into a private-disk zip BEFORE replacing
 * files, so a botched apply leaves a one-click restore zip behind.
 *
 * Update authorization:
 *   - The customer's license must be active (NOT grace, NOT expired).
 *     This is the only feature that gates immediately on expiry.
 *   - The applied zip's signature must verify against the embedded
 *     product public key (config/capstone.php license_public_key_b64).
 *     Same key signs licenses and signs releases.
 */
class UpdateService
{
    private const STAGING_DIR  = 'updates/staging';
    private const BACKUPS_DIR  = 'updates/backups';
    private const UPLOADS_DIR  = 'updates/uploads';

    /** Files we never overwrite during an in-place update. */
    private const PRESERVE_PATHS = [
        '.env',
        '.env.backup',
        'storage/',
        'public/storage',
        'public/.user.ini',
        'bootstrap/cache/',
    ];

    public function currentVersion(): string
    {
        return (string) config('capstone.product_version', '0.0.0');
    }

    /**
     * Fetch + verify the update manifest from the configured CDN.
     * Returns null on any failure so callers can fall back to "no
     * update available" rather than 500'ing the panel.
     */
    public function fetchManifest(): ?array
    {
        $url = (string) config('capstone.update_manifest_url', '');
        if ($url === '') return null;

        try {
            $resp = Http::timeout(10)->withHeaders([
                'User-Agent' => 'CapstoneNMS-Updater/' . $this->currentVersion(),
            ])->get($url);
        } catch (\Throwable $e) {
            Log::warning('UpdateService: manifest fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
        if (! $resp->successful()) return null;

        $envelope = trim((string) $resp->body());
        return $this->verifyEnvelope($envelope);
    }

    public function availableUpdate(): ?array
    {
        $manifest = $this->fetchManifest();
        if (! $manifest) return null;

        $latest = (string) ($manifest['latest'] ?? '');
        if ($latest === '' || ! version_compare($latest, $this->currentVersion(), '>')) {
            return null;
        }

        foreach ((array) ($manifest['releases'] ?? []) as $entry) {
            if (($entry['version'] ?? null) === $latest) return $entry;
        }
        return null;
    }

    /**
     * Apply an update entry pulled from the manifest. Downloads the
     * release zip, fetches the detached signature, verifies, then
     * delegates to applyZip().
     */
    public function applyDownload(array $entry): array
    {
        if (! $this->isUpdateAllowed()) {
            return ['ok' => false, 'errors' => ['Updates require an active license. Renew + re-upload your license to enable.']];
        }

        $url = (string) ($entry['url'] ?? '');
        $sigUrl = (string) ($entry['sig_url'] ?? '');
        $sha = strtolower((string) ($entry['checksum_sha256'] ?? ''));
        if ($url === '' || $sigUrl === '') {
            return ['ok' => false, 'errors' => ['Manifest entry is missing url / sig_url']];
        }

        $disk = Storage::disk('local');
        $zipPath = self::UPLOADS_DIR . '/' . Str::slug((string) ($entry['version'] ?? 'pending')) . '.zip';
        $sigPath = $zipPath . '.sig';

        try {
            $zipBytes = (string) Http::timeout(120)->get($url)->body();
            if ($zipBytes === '') return ['ok' => false, 'errors' => ['Empty zip download']];
            if ($sha !== '' && hash('sha256', $zipBytes) !== $sha) {
                return ['ok' => false, 'errors' => ['SHA-256 checksum mismatch on the downloaded zip']];
            }
            $sigBytes = (string) Http::timeout(15)->get($sigUrl)->body();
            $disk->put($zipPath, $zipBytes);
            $disk->put($sigPath, $sigBytes);
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Download failed: ' . $e->getMessage()]];
        }

        return $this->applyZip(
            $disk->path($zipPath),
            $disk->path($sigPath),
            (string) ($entry['version'] ?? ''),
        );
    }

    /**
     * Same pipeline as applyDownload, but the customer uploaded the
     * files manually (air-gapped install).
     */
    public function applyUploaded(string $zipPath, string $sigPath, string $version = ''): array
    {
        if (! $this->isUpdateAllowed()) {
            return ['ok' => false, 'errors' => ['Updates require an active license. Renew + re-upload your license to enable.']];
        }
        return $this->applyZip($zipPath, $sigPath, $version);
    }

    /**
     * The actual apply pipeline. Verifies signature, snapshots the
     * existing install, extracts the new version, runs migrations,
     * clears caches. On any step failure, returns errors so the UI
     * can show them; the snapshot zip stays on disk regardless so a
     * customer can restore manually.
     *
     * @return array{ok:bool, errors:array<int,string>, backup?:string, version?:string}
     */
    private function applyZip(string $zipPath, string $sigPath, string $version): array
    {
        if (! is_file($zipPath)) return ['ok' => false, 'errors' => ['Zip not found at ' . $zipPath]];
        if (! is_file($sigPath)) return ['ok' => false, 'errors' => ['Signature not found at ' . $sigPath]];

        $zipBytes = (string) file_get_contents($zipPath);
        $sigBytes = (string) file_get_contents($sigPath);
        if (! $this->verifyDetached($zipBytes, $sigBytes)) {
            return ['ok' => false, 'errors' => ['Signature verification failed for the update zip.']];
        }

        // Snapshot first.
        $backupRel = $this->snapshotInstall();
        if ($backupRel === null) {
            return ['ok' => false, 'errors' => ['Could not write pre-update snapshot — refusing to apply.']];
        }

        // Extract to staging, then rsync into base_path() (preserving env/storage).
        $stageRel = self::STAGING_DIR . '/' . Str::random(12);
        $stageAbs = Storage::disk('local')->path($stageRel);
        File::ensureDirectoryExists($stageAbs);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'errors' => ['Could not open update zip']];
        }
        if (! $zip->extractTo($stageAbs)) {
            $zip->close();
            return ['ok' => false, 'errors' => ['Zip extraction failed']];
        }
        $zip->close();

        // Most build pipelines wrap their files in a top-level
        // "capstone-nms/" directory. If we see exactly one directory
        // at the top level, descend into it.
        $entries = array_diff(scandir($stageAbs), ['.', '..']);
        if (count($entries) === 1) {
            $only = $stageAbs . '/' . array_values($entries)[0];
            if (is_dir($only)) $stageAbs = $only;
        }

        // Copy the new files in, preserving the customer's data and
        // configuration. We use a manual file walk rather than rsync
        // because rsync isn't guaranteed on shared hosting.
        $copyErrors = $this->mergeIntoInstall($stageAbs);
        if (! empty($copyErrors)) {
            return ['ok' => false, 'errors' => $copyErrors, 'backup' => $backupRel];
        }

        // Migrations + cache rebuild.
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            Artisan::call('config:cache');
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Post-install commands failed: ' . $e->getMessage()], 'backup' => $backupRel];
        }

        // Clean up staging.
        File::deleteDirectory(Storage::disk('local')->path(self::STAGING_DIR));

        return ['ok' => true, 'errors' => [], 'backup' => $backupRel, 'version' => $version];
    }

    /**
     * Verify either the manifest envelope OR a release-zip detached
     * signature against the embedded product public key.
     *
     * Manifest is the same wire format as a license: "<b64payload>.<b64sig>".
     * Release zips are signed with a detached signature stored in a
     * separate .sig file.
     */
    private function verifyEnvelope(string $envelope): ?array
    {
        $envelope = trim($envelope);
        if ($envelope === '' || ! str_contains($envelope, '.')) return null;

        $pub = $this->publicKey();
        if ($pub === null) return null;

        [$b64payload, $b64sig] = explode('.', $envelope, 2);
        $json = $this->b64UrlDecode($b64payload);
        $sig = $this->b64UrlDecode($b64sig);
        if ($json === '' || $sig === '' || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return null;

        try {
            if (! sodium_crypto_sign_verify_detached($sig, $json, $pub)) return null;
        } catch (\SodiumException) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function verifyDetached(string $contents, string $sigBytes): bool
    {
        $pub = $this->publicKey();
        if ($pub === null) return false;

        // Sigs are stored as base64-encoded text in the .sig file
        // (the build pipeline writes them this way for ease of
        // copy/paste). Accept either raw bytes or b64 — whichever
        // the file contains.
        if (strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $decoded = $this->b64UrlDecode(trim($sigBytes));
            if (strlen($decoded) === SODIUM_CRYPTO_SIGN_BYTES) {
                $sigBytes = $decoded;
            } else {
                return false;
            }
        }

        try {
            return sodium_crypto_sign_verify_detached($sigBytes, $contents, $pub);
        } catch (\SodiumException) {
            return false;
        }
    }

    private function publicKey(): ?string
    {
        $b64 = (string) config('capstone.license_public_key_b64', '');
        if ($b64 === '') return null;
        $raw = $this->b64UrlDecode($b64);
        return strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    private function b64UrlDecode(string $s): string
    {
        $pad = (4 - strlen($s) % 4) % 4;
        $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad), true);
        return $out === false ? '' : $out;
    }

    /**
     * Walk the install tree and copy it into a zip under
     * storage/app/private/updates/backups/. Returns the disk-relative
     * path on success or null on failure.
     */
    private function snapshotInstall(): ?string
    {
        $disk = Storage::disk('local');
        $rel = self::BACKUPS_DIR . '/' . Carbon::now()->format('Ymd-His') . '.zip';
        $abs = $disk->path($rel);
        File::ensureDirectoryExists(dirname($abs));

        $zip = new \ZipArchive();
        if ($zip->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $base = base_path();
        $skip = ['storage', 'vendor', 'node_modules', 'public/build', 'bootstrap/cache'];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iter as $f) {
            $relPath = substr($f->getPathname(), strlen($base) + 1);
            $top = explode(DIRECTORY_SEPARATOR, $relPath, 2)[0];
            if (in_array($top, $skip, true)) continue;
            if ($f->isDir()) continue;
            $zip->addFile($f->getPathname(), $relPath);
        }
        $zip->close();
        return $rel;
    }

    /**
     * Copy files from $stageAbs into the install. Layout-aware:
     *   - STANDARD: files merge into base_path() as-is.
     *   - SHARED:   non-public/* merge into base_path() (= _app/);
     *               public/* contents flatten up to dirname(base_path)
     *               (= the doc root).
     * Files in PRESERVE_PATHS are never overwritten regardless.
     *
     * @return array<int, string>
     */
    private function mergeIntoInstall(string $stageAbs): array
    {
        $errors = [];
        $base = base_path();
        $layout = app(\App\Services\Install\LayoutService::class)->persistedMode()
            ?? \App\Services\Install\LayoutService::MODE_STANDARD;
        $docRoot = $layout === \App\Services\Install\LayoutService::MODE_SHARED
            ? dirname($base)
            : null;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stageAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iter as $f) {
            $relPath = substr($f->getPathname(), strlen($stageAbs) + 1);
            if ($this->isPreserved($relPath)) continue;

            // SHARED layout remap: anything under public/* lands in
            // the doc root (one level up from base_path), with the
            // 'public/' prefix stripped. Everything else merges into
            // base_path() which is _app/.
            if ($docRoot !== null && (str_starts_with($relPath, 'public/') || $relPath === 'public')) {
                if ($relPath === 'public') {
                    // The directory itself doesn't exist in shared
                    // layout — its contents are flat at the doc root.
                    continue;
                }
                $rest = substr($relPath, strlen('public/'));
                $dest = $docRoot . DIRECTORY_SEPARATOR . $rest;
            } else {
                $dest = $base . DIRECTORY_SEPARATOR . $relPath;
            }

            try {
                if ($f->isDir()) {
                    File::ensureDirectoryExists($dest);
                } else {
                    File::ensureDirectoryExists(dirname($dest));
                    if (! @copy($f->getPathname(), $dest)) {
                        $errors[] = 'Could not write ' . $relPath;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $relPath . ': ' . $e->getMessage();
            }
        }
        return $errors;
    }

    private function isPreserved(string $relPath): bool
    {
        $rel = ltrim(str_replace('\\', '/', $relPath), '/');
        foreach (self::PRESERVE_PATHS as $p) {
            $p = rtrim($p, '/');
            if ($rel === $p) return true;
            if (str_starts_with($rel, $p . '/')) return true;
        }
        return false;
    }

    private function isUpdateAllowed(): bool
    {
        return app(LicenseService::class)->isUpdateAllowed();
    }
}
