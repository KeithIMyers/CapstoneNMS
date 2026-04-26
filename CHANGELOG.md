Live-progress UI for in-flight updates — the apply now writes a JSON progress file at every step (download / verify / snapshot / extract / merge / migrate) and the Filament Updates page polls it via Alpine.js, so customers see a real progress bar + step name instead of a frozen "applying" spinner. Filament header actions now closure-bound so they actually fire.

## 1.0.3-dev

- New `App\Services\Update\ProgressTracker` writes the apply's per-step state to `storage/app/private/updater/progress.json`.
- `UpdateService::applyDownload` + `applyZip` call the tracker at every major step, also bump `set_time_limit(0)` + `ignore_user_abort(true)` so a slow CDN or big snapshot doesn't get killed.
- New `UpdaterController::progress` JSON endpoint (`/capstone-updater/progress`) the page polls every 1.2s.
- Filament Updates page rewritten: header actions use closures (`->action(fn () => $this->method())`) instead of the unreliable string-form binding; new Alpine progress card lights up on the `updater-started` Livewire event AND on poll-detected running state; auto-reloads on completion.
- Apply success now `$this->redirect('/admin/updates')` so the post-apply view is rendered against the freshly-cached new version's view files.

## 1.0.2-dev

- New migration creates the `pages` table on installs that don't have it (the public footer hard-crashed with "Table 'pages' doesn't exist" — `pages` was provisioned by the legacy CodeCanyon SQL dump and never converted to a migration).
- `UpdateService::fetchManifest` appends a per-second `?b=` cache-buster + sends `Cache-Control: no-cache` so newly-published manifests aren't masked by Cloudflare's edge cache.
- Installer order: license envelope is saved BEFORE the first admin is created, so the User cap-check sees the active license instead of refusing the bootstrap admin.
- `InstallerService::writeEnv` reads from existing `.env` when present, preserving the SSH-bootstrapped APP_KEY across wizard submits.
- `install.sh` writes `.env` + APP_KEY before handing off to the web installer.
- Wizard view renames `$errors` → `$installErrors` so install failures actually show up at the top of the page (was shadowed by Laravel's `ViewErrorBag`).
- `0001_01_01_000003_legacy_user_columns` backfills `phone`, `slug`, `bio`, etc. on the users table so subsequent migrations can `->after('phone')` without dying.

## 1.0.1-dev

- New `LayoutService` detects standard / pre-layout / shared-host layouts.
- New "Relayout for shared hosting" page in the installer wizard appears when extraction lands in `public_html/`.
- `public/index.php` now finds the bootstrap regardless of layout (`./../`, `./_app/`, or current dir).
- `UpdateService` reads the layout marker and remaps incoming `public/` contents to the doc root on shared-host installs.

## 1.0.0-dev

Initial CapstoneNMS release. Forked + rebranded from USNews.today, full licensing pipeline (Ed25519 keygen + runtime verifier, kind=production/development/trial, tier caps, paywall feature gate), web installer with preflight, web updater with signed zip apply.
