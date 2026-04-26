# CapstoneNMS — News Management System

The Laravel 13 + Filament 4 application that ships as the **CapstoneNMS** product. This directory is the unprotected source — the development working tree.

For the licensing CLI, build pipeline, and customer-facing docs, see the parent `CapstoneNMS/` repository root:

```
CapstoneNMS/
├── source/      ← you are here
├── keygen/      ← license-mint CLI
├── scripts/     ← build / dist / docs pipelines
├── build/       ← intermediate (gitignored)
├── dist/        ← shippable archives (gitignored)
└── docs/        ← customer-facing docs
```

## Quick start (developer)

```bash
cd source
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan license:install-dev          # mints a kind=development license
npm install
npm run build
php artisan serve
```

Then visit `http://127.0.0.1:8000` for the public site or `http://127.0.0.1:8000/admin` for the panel. The dev banner will appear at the top of every page — it's intentional and stays on every kind=development license.

The dev license file is gitignored from `source/storage/app/private/licensing/` and stripped from `build/` by `scripts/build.sh`. It cannot accidentally end up in a customer dist.

## Architecture

- Public site, admin panel (Filament), AI agent runner, paywall, newsletter, podcasts, live blogs, recommendations, search.
- Role-based: admin / editor / author / agent (ghost-user). Tier-aware caps come from the license payload.
- Strict CSP on the public side, tightened CSP on `/admin/*`. Mass-assignment, SSRF, and XSS posture documented in `app/Http/Middleware/SecurityHeaders.php` and `app/Models/User.php`.
- Licensing: `App\Services\Licensing\LicenseService` (Phase B — Ed25519 signature, embedded public key, no phone-home).

## License

Proprietary. CapstoneNMS is licensed software; redistribution and resale require a written agreement.
