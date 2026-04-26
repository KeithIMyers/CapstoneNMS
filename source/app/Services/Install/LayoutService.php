<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\Storage;

/**
 * Filesystem-layout detection + the public_html relayout transform.
 *
 * Three layouts the codebase supports (see public/index.php for the
 * boot-side comment):
 *
 *   STANDARD     install/{app, bootstrap, ..., public/}
 *                — Laravel default, doc root points at install/public/
 *
 *   PRE_LAYOUT   public_html/{app, bootstrap, ..., public/}
 *                — customer extracted the dist directly into the
 *                  shared-host doc root. Boots, but the contents of
 *                  public/ aren't where the web server expects them.
 *                  Installer detects this and offers relayoutToShared().
 *
 *   SHARED       public_html/{ <public-files-flat>, _app/{app, bootstrap, ...} }
 *                — post-relayout. _app/ contains everything that was
 *                  outside public/; public/'s contents are flattened
 *                  into the doc root. .htaccess inside _app/ denies
 *                  direct web access.
 *
 * The marker file storage/app/private/.layout-mode contains the
 * canonical name ("standard" / "shared"). UpdateService reads this
 * to know how to merge the new zip's contents.
 */
class LayoutService
{
    public const MODE_STANDARD = 'standard';
    public const MODE_SHARED   = 'shared';

    private const MARKER_PATH = '.layout-mode';

    /** Names assumed to be Laravel-app top-level files/dirs. */
    private const APP_TREE = [
        'app', 'bootstrap', 'config', 'database', 'lang',
        'resources', 'routes', 'storage', 'vendor', 'artisan',
    ];

    /**
     * Detect the current layout. Returns one of:
     *   'standard' | 'shared' | 'pre_layout'
     */
    public function detect(): string
    {
        $base = base_path();
        $name = strtolower(basename($base));

        // pre_layout: base_path itself is the doc root (typically named
        // public_html / public). The Laravel app + a public/ subdir
        // both sit directly inside it.
        if (in_array($name, ['public_html', 'public', 'www', 'htdocs'], true)
            && is_dir($base . '/public')
            && is_file($base . '/artisan')) {
            return 'pre_layout';
        }

        // shared: app is at <doc-root>/_app/, doc root is base_path() . '/..'
        // We're inside _app/ when basename(base_path) is '_app' AND parent
        // contains the relayed flat doc root.
        if ($name === '_app') {
            return self::MODE_SHARED;
        }

        return self::MODE_STANDARD;
    }

    /**
     * Read the persisted layout mode written during install/relayout.
     * Returns null when the marker is absent (e.g. fresh install before
     * the wizard has run).
     */
    public function persistedMode(): ?string
    {
        try {
            if (! Storage::disk('local')->exists(self::MARKER_PATH)) return null;
            $mode = strtolower(trim((string) Storage::disk('local')->get(self::MARKER_PATH)));
            return in_array($mode, [self::MODE_STANDARD, self::MODE_SHARED], true) ? $mode : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function writeMarker(string $mode): void
    {
        Storage::disk('local')->put(self::MARKER_PATH, $mode);
    }

    /**
     * Move files from PRE_LAYOUT to SHARED:
     *   <docroot>/{app, bootstrap, ..., public/}
     *       → <docroot>/_app/{app, bootstrap, ...}
     *       + <docroot>/{<contents of public/ flattened>}
     *
     * Idempotent up to a point: refuses to run when _app/ already
     * exists (avoids clobbering an in-progress relayout).
     *
     * @return array{ok:bool, errors:array<int,string>, moved:int}
     */
    public function relayoutToShared(): array
    {
        $errors = [];
        $base = rtrim(base_path(), '/');
        $appDir = $base . '/_app';

        if ($this->detect() !== 'pre_layout') {
            return ['ok' => false, 'errors' => ['Not in pre-layout state — refusing relayout.'], 'moved' => 0];
        }
        if (is_dir($appDir)) {
            return ['ok' => false, 'errors' => ['_app/ already exists — refusing to overwrite. Remove it first if a previous relayout was interrupted.'], 'moved' => 0];
        }

        if (! @mkdir($appDir, 0755, true) && ! is_dir($appDir)) {
            return ['ok' => false, 'errors' => ['Could not create _app/ directory'], 'moved' => 0];
        }

        $moved = 0;

        // 1. Move every Laravel-app top-level entry into _app/.
        foreach (self::APP_TREE as $entry) {
            $src = $base . '/' . $entry;
            $dst = $appDir . '/' . $entry;
            if (! file_exists($src)) continue;
            if (! @rename($src, $dst)) {
                $errors[] = "Could not move {$entry} into _app/";
            } else {
                $moved++;
            }
        }

        // Also move .env / .env.example / composer.* / package.* /
        // vite.config.js — anything that's not a public/ child file.
        foreach (['.env', '.env.example', 'composer.json', 'composer.lock',
                  'package.json', 'package-lock.json', 'vite.config.js',
                  'phpunit.xml', 'README.md', 'TODO.md'] as $entry) {
            $src = $base . '/' . $entry;
            $dst = $appDir . '/' . $entry;
            if (! file_exists($src)) continue;
            if (! @rename($src, $dst)) {
                $errors[] = "Could not move {$entry} into _app/";
            } else {
                $moved++;
            }
        }

        // 2. Flatten the contents of public/ into the doc root.
        $publicDir = $appDir . '/public';
        if (is_dir($publicDir)) {
            $items = @scandir($publicDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $src = $publicDir . '/' . $item;
                $dst = $base . '/' . $item;
                // The customer's index.php (already shipped at the doc
                // root with the dist) was identical to the one in
                // public/; we let the new file overwrite it. Same for
                // .htaccess and any other public/* files.
                if (file_exists($dst)) {
                    @unlink($dst);
                }
                if (! @rename($src, $dst)) {
                    $errors[] = "Could not move public/{$item} to doc root";
                }
            }
            // Now public/ should be empty inside _app/. Remove it so
            // updates merge cleanly.
            @rmdir($publicDir);
        }

        // 3. Drop a .htaccess in _app/ to deny direct web access. The
        // doc-root .htaccess (Laravel's stock public/.htaccess, now
        // at the doc root) only rewrites requests that don't match a
        // file or directory; a literal request for /_app/.env would
        // bypass that. This Allow/Deny rule makes it return 403.
        $denyContent = <<<HTACCESS
# CapstoneNMS — deny direct access to the application tree.
# Web requests should always hit the doc-root index.php; nothing
# inside _app/ is meant to be served directly.
Order allow,deny
Deny from all

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
HTACCESS;
        @file_put_contents($appDir . '/.htaccess', $denyContent);

        // 4. Persist the mode marker so UpdateService knows how to
        // merge future updates.
        try {
            $this->writeMarker(self::MODE_SHARED);
        } catch (\Throwable $e) {
            $errors[] = 'Could not write layout marker: ' . $e->getMessage();
        }

        return [
            'ok'     => empty($errors),
            'errors' => $errors,
            'moved'  => $moved,
        ];
    }
}
