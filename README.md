# CapstoneNMS

A Laravel 13 + Filament 4 News Management System sold as a licensed product.

This repository is **internal**. The unprotected source lives under `source/`; the keygen CLI under `keygen/`; build, dist, and docs pipelines under `scripts/`. Customers receive only the contents of `dist/` — a signed zip plus their per-license token.

## Repository layout

```
CapstoneNMS/
├── source/      Unprotected Laravel application — your dev tree
├── keygen/      Ed25519 license-mint CLI (private key never ships)
├── scripts/
│   ├── build.sh     source/  → build/  (composer install + asset build)
│   ├── dist.sh      build/   → dist/   (zip + checksum + signature)
│   └── docs.sh      docs/    → build/docs/  (interpolate version)
├── build/       Intermediate artifacts (gitignored)
├── dist/        Shippable archives (gitignored)
└── docs/        Customer-facing docs (committed; rendered into build/)
```

## Phase status

| Phase | Scope | Status |
|---|---|---|
| **A** | Fork + scaffold + rebrand + tier defaults + LicenseService stub | ✅ this commit |
| **B** | Ed25519 license mint + verify + tier-cap enforcement + admin License page | pending |
| **C** | Web installer (`/install`) with server-requirements preflight | pending |
| **D** | Web updater (CDN manifest + signed-zip apply + rollback) | pending |
| **E** | Source protection pass on the licensing touchpoints | pending |

## Tier defaults (`source/config/capstone.php`)

| Tier | Admins | Editors | Authors | Agents | Paywall | Footer hideable |
|---|---|---|---|---|---|---|
| Solo | 1 | 0 | 0 | 1 | ✕ | ✕ (forced "Powered by CapstoneNMS") |
| Team | 2 | 3 | 10 | 3 | ✕ | ✓ |
| Pro | 5 | 10 | 25 | 10 | ✓ | ✓ |
| Enterprise | ∞ | ∞ | ∞ | ∞ | ✓ | ✓ |

Numbers are defaults; the keygen accepts overrides per license to support enterprise one-off deals.

## Developer quick start

```bash
cd source
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

`APP_ENV=local` triggers the LicenseService dev fallback (treats the install as Enterprise). Production environments without a valid license token lock the admin panel.

## Building a release

```bash
./scripts/build.sh                  # composer + npm + protections
./scripts/dist.sh                   # zip + checksum + signature
./scripts/docs.sh                   # render version into customer docs
ls dist/
# capstone-nms-1.0.0-dev.zip
# capstone-nms-1.0.0-dev.zip.sha256
# capstone-nms-1.0.0-dev.zip.sig    (Phase D)
```

## Minting a license

```bash
cd keygen
composer install
./bin/keygen bootstrap                    # one-time; produces the key pair
./bin/keygen mint \
    --tier=pro \
    --customer="Acme Corp" \
    --domains=acme.example,*.acme.example \
    --expires=2027-04-26 \
    --output=./out/acme-pro
```

See `keygen/README.md` for the full reference.

## License

Proprietary. CapstoneNMS is a commercial product. Distribution outside of an active license agreement is prohibited.
