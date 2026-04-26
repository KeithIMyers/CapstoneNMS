#!/usr/bin/env bash
#
# CapstoneNMS — SSH bootstrap installer
#
# Run on a shared host with SSH access to fetch the latest CapstoneNMS
# release, verify its Ed25519 signature, lay out the files in the
# right place for your hosting layout, and hand control to the web
# installer at /install.
#
# Usage:
#   curl -sSL https://update.capstonenms.com/install.sh | bash
#   curl -sSL https://update.capstonenms.com/install.sh | bash -s -- \
#       --doc-root=/home/me/public_html \
#       --layout=standard
#
# Or download + run interactively (recommended for first-time installs
# so you see the prompts):
#   curl -sSLO https://update.capstonenms.com/install.sh
#   bash install.sh
#
# Layout choices:
#   standard  Laravel app outside the doc root, doc root → public/
#             via symlink. Cleanest. Requires the ability to create
#             symlinks at the doc-root level (most shared hosts do).
#   shared    Laravel app inside the doc root under _app/, public/'s
#             contents flat at the doc root. Works on every host.
#             The web installer's own relayout step uses this layout.
#   auto      Try standard, fall back to shared if the symlink fails.
#             [default]

set -euo pipefail

CDN_BASE="https://update.capstonenms.com"
MANIFEST_URL="${CDN_BASE}/manifest.json"

# Embedded product public key. Mirror of source/config/capstone.php
# license_public_key_b64. The bundle on the CDN is signed with the
# matching private key (kept on the build/release machine).
PUBKEY_B64="Z0nQaB0y4SdTN3ebPoEBGK65feVmD4KIq7c8JGFhfuA"

DOC_ROOT=""
LAYOUT="auto"
APP_DIR=""              # for standard: ~/capstone-nms; for shared: <doc-root>/_app
ASSUME_YES=0
NONINTERACTIVE=0

# stdin isn't a tty when piping curl|bash — fall back to non-interactive.
if [ ! -t 0 ]; then
    NONINTERACTIVE=1
fi

TMPDIR=""
cleanup() {
    [ -n "${TMPDIR}" ] && [ -d "${TMPDIR}" ] && rm -rf "${TMPDIR}"
}
trap cleanup EXIT

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m  ✕\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<USAGE
CapstoneNMS — SSH bootstrap installer

Options:
  --doc-root=<path>    Path to the web doc root (e.g. /home/me/public_html)
  --layout=<mode>      standard | shared | auto  (default: auto)
  --app-dir=<path>     Where to place the Laravel app on standard layout
                       (default: <home>/capstone-nms)
  -y, --yes            Don't prompt; accept defaults / non-blocking choices
  -h, --help           Show this help

Behavior:
  1. Downloads + verifies the latest signed manifest from the CDN.
  2. Downloads + verifies the release zip's Ed25519 signature + sha256.
  3. Extracts to a temp dir.
  4. Lays out the files according to --layout:
        standard:  app at <app-dir>, doc root symlinked to <app-dir>/public
        shared:    app at <doc-root>/_app, public/ contents flat at doc root
  5. Tells you the URL to visit to finish setup in the web installer.
USAGE
}

# ---------- arg parsing ------------------------------------------------

for arg in "$@"; do
    case "$arg" in
        --doc-root=*) DOC_ROOT="${arg#*=}" ;;
        --layout=*)   LAYOUT="${arg#*=}" ;;
        --app-dir=*)  APP_DIR="${arg#*=}" ;;
        -y|--yes)     ASSUME_YES=1 ;;
        -h|--help)    usage; exit 0 ;;
        *) die "Unknown argument: $arg (see --help)" ;;
    esac
done

case "${LAYOUT}" in
    standard|shared|auto) ;;
    *) die "Invalid --layout=${LAYOUT} (must be standard, shared, or auto)" ;;
esac

# ---------- requirements check ----------------------------------------

log "Checking requirements"
need=()
for cmd in php unzip curl rm mv ln mkdir; do
    command -v "$cmd" >/dev/null 2>&1 || need+=("$cmd")
done
[ ${#need[@]} -gt 0 ] && die "Missing required commands: ${need[*]}"
ok "all required commands present"

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
PHP_OK="$(php -r 'echo version_compare(PHP_VERSION, "8.3.0", ">=") ? "ok" : "fail";')"
[ "${PHP_OK}" = "ok" ] || die "PHP 8.3+ required (you have ${PHP_VERSION})"
ok "PHP ${PHP_VERSION}"

php -r 'extension_loaded("sodium") or exit(1);' \
    || die "PHP extension 'sodium' is required for signature verification."
ok "ext-sodium loaded"

# ---------- doc-root discovery ----------------------------------------

if [ -z "${DOC_ROOT}" ]; then
    log "Looking for your doc root"
    candidates=()
    [ -d "${HOME}/public_html" ] && candidates+=("${HOME}/public_html")
    if [ -d "${HOME}/domains" ]; then
        for d in "${HOME}"/domains/*/public_html; do
            [ -d "$d" ] && candidates+=("$d")
        done
    fi

    if [ ${#candidates[@]} -eq 0 ]; then
        if [ "${NONINTERACTIVE}" -eq 1 ]; then
            die "Couldn't auto-detect a doc root. Re-run with --doc-root=/path/to/public_html"
        fi
        printf 'Enter the absolute path to your doc root (e.g. /home/me/public_html): '
        read -r DOC_ROOT
    elif [ ${#candidates[@]} -eq 1 ]; then
        DOC_ROOT="${candidates[0]}"
        ok "auto-detected ${DOC_ROOT}"
    else
        if [ "${NONINTERACTIVE}" -eq 1 ]; then
            die "Multiple candidate doc roots found; pass --doc-root=… to choose. Candidates: ${candidates[*]}"
        fi
        echo "Multiple candidate doc roots found:"
        n=1; for c in "${candidates[@]}"; do echo "  $n) $c"; n=$((n+1)); done
        printf 'Pick one [1-%d]: ' "${#candidates[@]}"
        read -r choice
        DOC_ROOT="${candidates[$((choice - 1))]}"
    fi
fi

[ -d "${DOC_ROOT}" ] || die "Doc root ${DOC_ROOT} does not exist"
[ -w "${DOC_ROOT}" ] || die "Doc root ${DOC_ROOT} is not writable by this user"
DOC_ROOT="$(cd "${DOC_ROOT}" && pwd)"   # resolve to absolute
ok "doc root: ${DOC_ROOT}"

if [ -z "${APP_DIR}" ]; then
    APP_DIR="${HOME}/capstone-nms"
fi

# ---------- safety check ----------------------------------------------

# Refuse to clobber an existing install. Customer can delete the
# install lock + redeploy if they want a fresh start.
if [ -f "${DOC_ROOT}/_app/storage/app/private/installer/installed.lock" ] \
   || [ -f "${APP_DIR}/storage/app/private/installer/installed.lock" ]; then
    die "An existing CapstoneNMS install was detected. Move or delete it first."
fi

if [ -e "${DOC_ROOT}/_app" ]; then
    if [ "${ASSUME_YES}" -eq 0 ] && [ "${NONINTERACTIVE}" -eq 0 ]; then
        printf '%s/_app already exists. Overwrite? [y/N]: ' "${DOC_ROOT}"
        read -r confirm
        [ "${confirm}" = "y" ] || [ "${confirm}" = "Y" ] || die "Aborted by user"
    elif [ "${ASSUME_YES}" -eq 0 ]; then
        die "${DOC_ROOT}/_app already exists. Re-run with -y to overwrite."
    fi
    rm -rf "${DOC_ROOT}/_app"
fi

# ---------- fetch manifest --------------------------------------------

TMPDIR="$(mktemp -d -t capstone-XXXXXX)"
log "Fetching manifest from ${MANIFEST_URL}"
ENVELOPE_PATH="${TMPDIR}/manifest.envelope"
curl -fsSL --max-time 30 -o "${ENVELOPE_PATH}" "${MANIFEST_URL}" \
    || die "Could not fetch manifest"

# Verify signature + extract release URL via embedded PHP.
RELEASE_INFO="$(php <<PHP
<?php
\$envelope = trim(file_get_contents('${ENVELOPE_PATH}'));
\$pubB64 = '${PUBKEY_B64}';
\$pad = (4 - strlen(\$pubB64) % 4) % 4;
\$pub = base64_decode(strtr(\$pubB64, '-_', '+/').str_repeat('=', \$pad), true);
[\$bp, \$bs] = explode('.', \$envelope, 2);
\$padp = (4 - strlen(\$bp) % 4) % 4;
\$json = base64_decode(strtr(\$bp, '-_', '+/').str_repeat('=', \$padp), true);
\$pads = (4 - strlen(\$bs) % 4) % 4;
\$sig = base64_decode(strtr(\$bs, '-_', '+/').str_repeat('=', \$pads), true);
if (! sodium_crypto_sign_verify_detached(\$sig, \$json, \$pub)) { fwrite(STDERR, "BAD_SIG\n"); exit(2); }
\$d = json_decode(\$json, true);
\$r = \$d['releases'][0];
echo \$r['version']."\t".\$r['url']."\t".\$r['sig_url']."\t".\$r['checksum_sha256']."\n";
PHP
)" || die "Manifest signature verification failed"

VERSION="$(echo "${RELEASE_INFO}" | cut -f1)"
ZIP_URL="$(echo  "${RELEASE_INFO}" | cut -f2)"
SIG_URL="$(echo  "${RELEASE_INFO}" | cut -f3)"
SHA256="$(echo   "${RELEASE_INFO}" | cut -f4)"
ok "latest: ${VERSION}"

# ---------- fetch + verify zip ----------------------------------------

ZIP_PATH="${TMPDIR}/release.zip"
SIG_PATH="${TMPDIR}/release.zip.sig"

log "Downloading ${ZIP_URL}"
curl -fSL --progress-bar -o "${ZIP_PATH}" "${ZIP_URL}" \
    || die "Could not download release zip"

log "Downloading detached signature"
curl -fsSL -o "${SIG_PATH}" "${SIG_URL}" \
    || die "Could not download signature"

ACTUAL_SHA="$(php -r "echo hash_file('sha256', '${ZIP_PATH}');")"
[ "${ACTUAL_SHA}" = "${SHA256}" ] || die "Checksum mismatch (manifest expects ${SHA256}, got ${ACTUAL_SHA})"
ok "sha256 matches manifest"

php <<PHP || die "Zip signature verification failed"
<?php
\$pubB64 = '${PUBKEY_B64}';
\$pad = (4 - strlen(\$pubB64) % 4) % 4;
\$pub = base64_decode(strtr(\$pubB64, '-_', '+/').str_repeat('=', \$pad), true);
\$bytes = file_get_contents('${ZIP_PATH}');
\$sig = trim(file_get_contents('${SIG_PATH}'));
\$pad = (4 - strlen(\$sig) % 4) % 4;
\$sig = base64_decode(strtr(\$sig, '-_', '+/').str_repeat('=', \$pad), true);
if (! sodium_crypto_sign_verify_detached(\$sig, \$bytes, \$pub)) { exit(2); }
PHP
ok "Ed25519 signature verifies against the embedded product key"

# ---------- extract ----------------------------------------------------

log "Extracting"
EXTRACT_ROOT="${TMPDIR}/unzipped"
mkdir -p "${EXTRACT_ROOT}"
unzip -q "${ZIP_PATH}" -d "${EXTRACT_ROOT}"

# The build pipeline wraps the dist in a single top-level
# `capstone-nms/` directory.
EXTRACT_DIR="${EXTRACT_ROOT}/capstone-nms"
[ -d "${EXTRACT_DIR}" ] || EXTRACT_DIR="${EXTRACT_ROOT}"
[ -f "${EXTRACT_DIR}/artisan" ] || die "Extract layout looks wrong (no artisan in ${EXTRACT_DIR})"
ok "extracted to temp"

# ---------- layout decision -------------------------------------------

if [ "${LAYOUT}" = "auto" ]; then
    log "Probing layout choices"
    # Try a symlink at a non-clobbering path next to the doc root.
    PROBE_TARGET="${EXTRACT_DIR}/public"
    PROBE_LINK="${DOC_ROOT}.capstone-symlink-probe"
    if ln -s "${PROBE_TARGET}" "${PROBE_LINK}" 2>/dev/null; then
        rm -f "${PROBE_LINK}"
        LAYOUT="standard"
        ok "symlinks work — choosing STANDARD layout"
    else
        LAYOUT="shared"
        warn "symlinks denied — falling back to SHARED layout"
    fi
fi

# ---------- move into place -------------------------------------------

case "${LAYOUT}" in
    standard)
        log "Standard layout"
        echo "  app:      ${APP_DIR}"
        echo "  doc root: ${DOC_ROOT}  →  ${APP_DIR}/public  (symlink)"

        if [ -e "${APP_DIR}" ]; then
            if [ "${ASSUME_YES}" -eq 0 ] && [ "${NONINTERACTIVE}" -eq 0 ]; then
                printf '%s already exists. Overwrite? [y/N]: ' "${APP_DIR}"
                read -r confirm
                [ "${confirm}" = "y" ] || [ "${confirm}" = "Y" ] || die "Aborted"
            elif [ "${ASSUME_YES}" -eq 0 ]; then
                die "${APP_DIR} already exists. Re-run with -y to overwrite."
            fi
            rm -rf "${APP_DIR}"
        fi

        mkdir -p "$(dirname "${APP_DIR}")"
        mv "${EXTRACT_DIR}" "${APP_DIR}"

        # Doc-root swap. Save anything that was in there (e.g. the
        # default cPanel index.html) into a .bak; never overwrite a
        # file the customer might have hand-edited.
        if [ -L "${DOC_ROOT}" ]; then
            rm "${DOC_ROOT}"
            ln -s "${APP_DIR}/public" "${DOC_ROOT}"
        else
            BAK="${DOC_ROOT}.bak.$(date +%Y%m%d-%H%M%S)"
            mv "${DOC_ROOT}" "${BAK}"
            warn "moved old doc root to ${BAK}"
            ln -s "${APP_DIR}/public" "${DOC_ROOT}"
        fi

        # Mark the layout so the runtime UpdateService knows.
        mkdir -p "${APP_DIR}/storage/app/private"
        echo standard > "${APP_DIR}/storage/app/private/.layout-mode"

        chmod -R u+rw "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" 2>/dev/null || true
        ;;

    shared)
        log "Shared layout"
        echo "  doc root: ${DOC_ROOT}  (Laravel app inside _app/)"

        # Move the entire extract into the doc root, then run the
        # same relayout shape the web installer applies.
        SHARED_APP="${DOC_ROOT}/_app"
        mkdir -p "${SHARED_APP}"

        # Move every Laravel-app directory + dotfile + composer file
        # into _app/.
        for entry in app bootstrap config database lang resources routes storage vendor artisan \
                     .env.example composer.json composer.lock package.json package-lock.json \
                     vite.config.js phpunit.xml README.md TODO.md; do
            if [ -e "${EXTRACT_DIR}/${entry}" ]; then
                mv "${EXTRACT_DIR}/${entry}" "${SHARED_APP}/${entry}"
            fi
        done

        # Flatten public/* up to the doc root.
        for item in $(ls -A "${EXTRACT_DIR}/public" 2>/dev/null); do
            src="${EXTRACT_DIR}/public/${item}"
            dst="${DOC_ROOT}/${item}"
            [ -e "${dst}" ] && rm -rf "${dst}"
            mv "${src}" "${dst}"
        done
        rm -rf "${EXTRACT_DIR}/public" "${EXTRACT_DIR}"

        # Drop the deny-from-all .htaccess in _app/.
        cat > "${SHARED_APP}/.htaccess" <<'HTACCESS'
# CapstoneNMS — deny direct web access to the application tree.
Order allow,deny
Deny from all

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
HTACCESS

        mkdir -p "${SHARED_APP}/storage/app/private"
        echo shared > "${SHARED_APP}/storage/app/private/.layout-mode"

        chmod -R u+rw "${SHARED_APP}/storage" "${SHARED_APP}/bootstrap/cache" 2>/dev/null || true
        ;;
esac

ok "files in place"

# ---------- finish ----------------------------------------------------

cat <<DONE

================================================================
  CapstoneNMS ${VERSION} bootstrapped — ${LAYOUT} layout

  Visit your site to finish setup:

      https://<your-domain>/install

  The web installer will run preflight, ask for database
  credentials, create your first admin, and accept your
  license file.

  Layout marker:  $([ "${LAYOUT}" = "standard" ] && echo "${APP_DIR}" || echo "${DOC_ROOT}/_app")/storage/app/private/.layout-mode
================================================================

DONE
