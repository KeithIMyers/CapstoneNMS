Updates page UX overhaul — progress bar now styled to match the Filament panel, animates with a moving stripe so pauses between steps still show motion, only visible during an apply (no lingering "complete" card), and the air-gapped "Apply uploaded files" submit button moved next to its form. The apply pipeline also force-clears compiled view caches so future upgrades never get bitten by stale-cache 500s.

## 1.0.4-dev

- Updates page progress card restyled to Filament theme (`var(--primary-*)`, `var(--gray-*)`, fi-section wrapper).
- Progress bar uses Alpine `:style="{ width }"` object syntax (string form was replacing the whole style attr); added a CSS-keyframe diagonal stripe so the bar shows motion even between percent updates.
- Card visibility tightened: status=running OR finished within last 30s. No more lingering "complete" card on subsequent page loads.
- "Apply uploaded files" submit button moved out of the header actions and into the air-gapped install form so the form + its action are visually together.
- `UpdateService::applyZip` now `File::cleanDirectory()`s the compiled-view + cache-data dirs and `touch()`es every freshly-merged blade/php file. Resets opcache when available. Stale-view 500s after upgrade are gone.

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
