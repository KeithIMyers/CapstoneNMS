# CapstoneNMS — Installation Guide (v{{VERSION}})

Welcome. This guide walks you through standing up CapstoneNMS on your server.

## Server requirements

The web installer's preflight will check all of these automatically. Listed here for reference:

- PHP 8.3 or newer
- PHP extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd` or `imagick`, `intl`, `mbstring`, `openssl`, `pdo`, `pdo_mysql` (or `pdo_sqlite`), `tokenizer`, `xml`, `zip`
- MySQL 8.0+ / MariaDB 10.6+ / SQLite 3.35+
- Apache with `mod_rewrite`, or nginx with the standard Laravel rewrite block
- Writable: `storage/`, `bootstrap/cache/`, `public/storage/` (symlinked)
- Outbound HTTPS (for image proxy fetches and update manifest checks — both can be disabled)
- ≥256 MB `memory_limit` (512 MB recommended)
- ≥500 MB free disk

## Install steps

1. Download `capstone-nms-{{VERSION}}.zip` and the matching `.sha256` from your CapstoneNMS account.
2. Verify the archive checksum: `sha256sum -c capstone-nms-{{VERSION}}.zip.sha256`
3. Extract into your web root: `unzip capstone-nms-{{VERSION}}.zip -d /var/www/`
4. Point your web server at `capstone-nms/public/`.
5. Browse to your domain. The web installer takes over from here:
    - Server-requirements preflight (with green checks / red blockers)
    - Database connection settings (host, port, database, user, password)
    - Site basics (site name, default email, default locale)
    - First admin user creation
    - License upload (paste-blob or file upload — both supported)
    - Final review + commit

The installer creates `storage/installed.lock` once it finishes; subsequent visits to `/install` are blocked.

## Re-running the installer

If you need to reconfigure (database moved, license rotated), delete `storage/installed.lock` and visit `/install` again. The installer detects existing schemas and skips already-applied migrations.

## CLI alternative

For air-gapped installs without browser access:

```bash
php artisan capstone:install --interactive
```

This prompts for the same values the web installer collects. The license file can be passed via `--license=/path/to/file.dat`.

## Updates

Updates are managed from Site Settings → Updates in the admin panel. See [UPDATES.md](UPDATES.md).

## Support

Licensed customers: support@capstonenms.com
