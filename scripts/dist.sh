#!/usr/bin/env bash
set -euo pipefail

# CapstoneNMS — build/ → dist/
#
# Produces a shippable archive with sidecar checksum + signature.
# The signature is the same Ed25519 key used by the keygen — the
# admin Updates page uses it to verify uploaded update zips before
# extracting them.
#
# Usage: ./scripts/dist.sh [version]

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build/capstone-nms"
DIST="${ROOT}/dist"

if [ ! -d "${BUILD}" ]; then
  echo "ERROR: ${BUILD} doesn't exist. Run scripts/build.sh first." >&2
  exit 1
fi

VERSION="${1:-$(grep "'product_version'" "${ROOT}/source/config/capstone.php" | head -1 | awk -F"'" '{print $4}')}"
[ -z "${VERSION}" ] && VERSION="dev"

mkdir -p "${DIST}"
ARCHIVE="${DIST}/capstone-nms-${VERSION}.zip"

echo "==> Zipping build/ → ${ARCHIVE}"
( cd "${ROOT}/build" && zip -qr "${ARCHIVE}" capstone-nms )

echo "==> SHA-256"
( cd "${DIST}" && sha256sum "$(basename "${ARCHIVE}")" > "$(basename "${ARCHIVE}").sha256" )

# Phase D: sign the zip with the same Ed25519 key the keygen uses
# so the admin Updates page can verify before extraction. The
# private key lives in keygen/keys/private.key and is NEVER copied
# into the dist artifact.
KEY="${ROOT}/keygen/keys/private.key"
if [ -f "${KEY}" ]; then
  echo "==> Signing with keygen private key"
  php "${ROOT}/keygen/bin/keygen" sign "${ARCHIVE}" --key "${KEY}" --out "${ARCHIVE}.sig" 2>/dev/null || \
    echo "    (skipped — keygen sign subcommand lands in Phase D)"
else
  echo "==> No keygen private key at ${KEY} — distribution will be unsigned"
  echo "    (Customers won't be able to apply this archive via the admin Updates UI.)"
fi

echo "==> dist/ artifacts:"
ls -la "${DIST}"
