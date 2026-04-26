#!/usr/bin/env bash
set -euo pipefail

# CapstoneNMS — dist/ → update.capstonenms.com
#
# Rsyncs every artifact in dist/ to the customer-facing CDN host.
# SSH key auth required; the running user must already have a key
# accepted by capstone@64.20.40.243.
#
# Pushed contents:
#   capstone-nms-X.Y.Z.zip            release bundle
#   capstone-nms-X.Y.Z.zip.sha256     checksum sidecar
#   capstone-nms-X.Y.Z.zip.sig        Ed25519 signature
#   manifest.json                     signed update manifest (envelope)
#
# manifest.json on the remote is overwritten on every deploy to
# point at the latest release. The runtime UpdateService verifies
# the signature against the embedded product public key before any
# customer install acts on it.
#
# Old zips are NOT deleted by this script — historic versions stay
# reachable at their fixed URLs as long as the bandwidth budget
# allows. Use --delete in DEPLOY_RSYNC_FLAGS to clean up.
#
# Usage:
#   ./scripts/deploy.sh
#
# Environment overrides:
#   DEPLOY_UPDATE_USER    default: capstone
#   DEPLOY_UPDATE_HOST    default: 64.20.40.243
#   DEPLOY_UPDATE_PATH    default: /domains/update.capstonenms.com/public_html/
#   DEPLOY_UPDATE_PORT    default: 22
#   DEPLOY_RSYNC_FLAGS    extra rsync flags (e.g. --dry-run, --delete)

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${ROOT}/dist"

REMOTE_USER="${DEPLOY_UPDATE_USER:-capstone}"
REMOTE_HOST="${DEPLOY_UPDATE_HOST:-64.20.40.243}"
REMOTE_PATH="${DEPLOY_UPDATE_PATH:-domains/update.capstonenms.com/public_html/}"
SSH_PORT="${DEPLOY_UPDATE_PORT:-22}"
EXTRA_FLAGS="${DEPLOY_RSYNC_FLAGS:-}"

if [ ! -d "${DIST}" ]; then
  echo "ERROR: ${DIST} doesn't exist. Run scripts/build.sh + scripts/dist.sh first." >&2
  exit 1
fi

if [ -z "$(ls -A "${DIST}" 2>/dev/null)" ]; then
  echo "ERROR: ${DIST} is empty. Nothing to deploy." >&2
  exit 1
fi

# Sanity check: if there's a manifest, make sure its signature
# verifies before we push it. A mis-signed manifest on the CDN
# would cause every customer's UpdateService to reject the update.
if [ -f "${DIST}/manifest.json" ]; then
  PUB_B64="$(grep "'license_public_key_b64'" "${ROOT}/source/config/capstone.php" | head -1 | awk -F"'" '{for(i=1;i<=NF;i++) if($i ~ /^[A-Za-z0-9_-]{32,}$/) {print $i; exit}}')"
  if [ -n "${PUB_B64}" ]; then
    php -r '
      $env = trim(file_get_contents($argv[1]));
      $pubB64 = $argv[2];
      $pad = (4 - strlen($pubB64) % 4) % 4;
      $pub = base64_decode(strtr($pubB64, "-_", "+/").str_repeat("=", $pad), true);
      [$b64p, $b64s] = explode(".", $env, 2);
      $padp = (4 - strlen($b64p) % 4) % 4;
      $json = base64_decode(strtr($b64p, "-_", "+/").str_repeat("=", $padp), true);
      $pads = (4 - strlen($b64s) % 4) % 4;
      $sig = base64_decode(strtr($b64s, "-_", "+/").str_repeat("=", $pads), true);
      $ok = sodium_crypto_sign_verify_detached($sig, $json, $pub);
      if (! $ok) { fwrite(STDERR, "FATAL: manifest.json signature does not verify against the embedded product key. Refusing to deploy.\n"); exit(1); }
    ' "${DIST}/manifest.json" "${PUB_B64}"
    echo "==> Pre-flight: manifest signature verified"
  else
    echo "WARN: could not extract public key from source/config/capstone.php — skipping manifest verify."
  fi
fi

ARTIFACT_COUNT="$(find "${DIST}" -maxdepth 1 -type f | wc -l)"
echo "==> Deploying ${ARTIFACT_COUNT} artifact(s) → ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

# shellcheck disable=SC2086
rsync -avz \
  -e "ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new" \
  --human-readable \
  --progress \
  ${EXTRA_FLAGS} \
  "${DIST}/" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

echo ""
echo "==> Done."
echo "    manifest:  https://update.capstonenms.com/manifest.json"
echo "    releases:  https://update.capstonenms.com/capstone-nms-*.zip"
