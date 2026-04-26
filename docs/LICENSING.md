# CapstoneNMS — Licensing (v{{VERSION}})

CapstoneNMS is licensed software. Each license token unlocks the product for a specific list of domains, a specific tier, and an expiry date.

## License kinds

Every license token carries a **kind**, signed into the payload alongside the tier and domains:

| Kind | Public banner | Issued for | Domain restrictions |
|---|---|---|---|
| `production` | hidden (paying customer) | live customer deployment | per the licensed domain list |
| `development` | always shown — "This is a dev build, report unlicensed use…" | customer staging/dev environments | typically loopback + customer's dev domains |
| `trial` | shown — "Trial — N days remaining" | evaluation prospects | broad, short-expiry |

The kind is part of the signed payload, so a pirate can't flip a `development` license to `production` by editing the file — the signature breaks immediately.

## Tiers

| Tier | Admins | Editors | Authors | Agents | Paywall | "Powered by" footer |
|---|---|---|---|---|---|---|
| **Solo** | 1 | 0 | 0 | 1 | – | required |
| **Team** | 2 | 3 | 10 | 3 | – | hideable |
| **Pro** | 5 | 10 | 25 | 10 | included | hideable |
| **Enterprise** | unlimited | unlimited | unlimited | unlimited | included | hideable |

All tiers include the full editorial workflow, AI agent surface, recommendations, newsletter, podcasts, live blogs, and search. The differences are headcount + premium features.

## What "no phone-home" means

CapstoneNMS validates your license entirely locally. The product never calls back to capstonenms.com to check whether you're still paid up — your license file is signed cryptographically and the runtime verifies the signature with an embedded public key.

This means:

- Your install can run fully air-gapped
- Your usage data stays on your servers
- Your license doesn't suddenly stop working if our infrastructure has an outage

In exchange, when you renew your subscription you re-upload a fresh license file from your CapstoneNMS account. There's no "automatic" re-licensing.

## Domain locking

Each license carries a list of domains it permits. If you serve CapstoneNMS from a host that isn't on the list, the admin panel locks down with a "License domain mismatch" notice.

Wildcards (`*.acme.example`) are supported.

Localhost / `127.0.0.1` / `*.test` / `*.local` / `*.localhost` are always permitted **for local development** — the runtime checks `APP_ENV` and the absence of any `X-Forwarded-*` headers before granting the dev exemption, so a production install reverse-proxied through a localhost-fronted server doesn't qualify.

## Expiry behavior

| Days past expiry | Updates | Editorial | Public site |
|---|---|---|---|
| 0 | blocked | works | works |
| 1–14 (grace) | blocked, renewal banner shown | works | works |
| 15+ | blocked | read-only | works (with renewal banner in admin only) |

The public-facing site keeps serving readers regardless. Admin actions degrade so you have time to renew without an emergency.

## Re-licensing

In Site Settings → License, drop your renewed license file (or paste the blob form) and click Save. The new payload replaces the old one immediately; no restart needed.

## Cancellation

Cancelling your subscription means you don't receive future renewals. Your existing license continues to work until its `expires_at` lapses — then the schedule above applies.

## Questions

Licensing inquiries: licensing@capstonenms.com
