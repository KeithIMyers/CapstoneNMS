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
| **A** | Fork + scaffold + rebrand + tier defaults + LicenseService stub | ✅ |
| **A.1** | Strip `APP_ENV` bypass; license `kind` (production/development/trial); file-based licensing; tightened host check; public + admin dev banner; `license:install-dev` CLI | ✅ |
| **B** | Ed25519 keygen CLI (bootstrap / mint / verify); runtime verifier; tier-cap enforcement on user save; paywall feature gate; `EnforceLicense` middleware; admin License page | ✅ |
| **C** | Web installer at `/install` with server-requirements preflight; first-run `.env` + APP_KEY bootstrap; CLI fallback `php artisan capstone:install --interactive` | ✅ |
| **D** | Web updater: signed manifest pull from `update.capstonenms.com`, signed-zip apply with snapshot/rollback, air-gapped manual upload | ✅ |
| **E** | Source protection: comment + whitespace strip on the licensing files at build time; release zip signed with the same Ed25519 key | ✅ this commit |
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
./scripts/release.sh 1.0.0          # build + dist + deploy in one
# OR step by step:
./scripts/build.sh   1.0.0          # composer + npm + protections
./scripts/dist.sh    1.0.0          # zip + checksum + signature + manifest
./scripts/deploy.sh                 # rsync dist/ → update.capstonenms.com
./scripts/docs.sh    1.0.0          # render version into customer docs

ls dist/
# capstone-nms-1.0.0.zip
# capstone-nms-1.0.0.zip.sha256
# capstone-nms-1.0.0.zip.sig
# manifest.json
```

`scripts/release.sh 1.0.0 --dry-run` builds + bundles locally without deploying — useful for inspecting the artifact before pushing to the CDN.

The deploy step rsyncs to `capstone@64.20.40.243:/domains/update.capstonenms.com/public_html/` over SSH (key auth). Override via `DEPLOY_UPDATE_USER` / `DEPLOY_UPDATE_HOST` / `DEPLOY_UPDATE_PATH` / `DEPLOY_UPDATE_PORT` env vars. Pre-flight verifies the manifest signature against the embedded product key before pushing — a mis-signed manifest on the CDN would cause every customer's UpdateService to reject the update.

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
