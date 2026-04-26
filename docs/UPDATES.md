# CapstoneNMS — Updates (v{{VERSION}})

CapstoneNMS updates are managed from **Site Settings → Updates** in the admin panel — no terminal access required.

## How it works

1. The admin panel checks `https://updates.capstonenms.com/manifest.json` once per day. The manifest is a signed JSON document listing every released version, its download URL, signature, and minimum-PHP / migration notes.
2. When a new version is available, you see an "Update available" banner.
3. Click **Update** → CapstoneNMS:
    - Downloads the signed `.zip` from the CDN
    - Verifies the Ed25519 signature against the embedded product public key
    - Snapshots the current install (database dump + file diff manifest) into `storage/backups/`
    - Extracts the new version into a staging directory
    - Runs `php artisan migrate --force`
    - Atomically swaps in the new files
    - Clears caches
4. If any step fails, the rollback path restores from the snapshot.

## Air-gapped updates

Customers without outbound HTTPS to the manifest URL can:

1. Download `capstone-nms-X.Y.Z.zip` and its `.sig` file manually from your CapstoneNMS account
2. Visit Site Settings → Updates → "Upload an update file"
3. Drop both files in the form. The same verify + apply pipeline runs locally.

## Update gating

Updates require an **active** license — past expiry, you can keep running CapstoneNMS through the 14-day grace window for everything else, but the Updates UI shows a renewal CTA instead of an Update button. Renew, re-upload your fresh license, and the Update button comes back.

This is the only feature gated immediately on license expiry.

## Changelog

Each release ships with a `CHANGELOG.md` that the Updates UI surfaces inline so you can review what's changing before you click Update.
