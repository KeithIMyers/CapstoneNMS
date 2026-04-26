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

# Sign the zip with the keygen Ed25519 private key. The admin
# Updates page verifies this signature against the same embedded
# product public key it uses for license envelopes before any
# files are touched.
KEY="${ROOT}/keygen/keys/private.key"
if [ -f "${KEY}" ]; then
  echo "==> Signing with keygen private key"
  php -r '
    $zip = $argv[1]; $keyPath = $argv[2]; $sigOut = $argv[3];
    $bytes = file_get_contents($zip);
    $secret = file_get_contents($keyPath);
    if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Private key wrong length\n"); exit(1);
    }
    $sig = sodium_crypto_sign_detached($bytes, $secret);
    file_put_contents($sigOut, rtrim(strtr(base64_encode($sig), "+/", "-_"), "="));
    echo "  sig written: ".basename($sigOut)."\n";
  ' "${ARCHIVE}" "${KEY}" "${ARCHIVE}.sig"
else
  echo "==> No keygen private key at ${KEY} — distribution will be unsigned"
  echo "    (Customers won't be able to apply this archive via the admin Updates UI.)"
  echo "    Run: cd keygen && composer install && ./bin/keygen bootstrap"
fi

echo "==> dist/ artifacts:"
ls -la "${DIST}"
