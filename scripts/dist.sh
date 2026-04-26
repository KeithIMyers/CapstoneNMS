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

# Generate a signed update manifest fragment. This is the same wire
# format the runtime UpdateService::fetchManifest() expects from
# https://update.capstonenms.com/manifest.json — a single-release
# manifest you can publish as-is, or merge into a larger
# multi-release manifest at the CDN.
#
# Release notes can be passed via the CHANGELOG_FILE env var. If
# unset, falls back to the first paragraph of CHANGELOG.md (when
# present) or a generic note.
if [ -f "${KEY}" ]; then
  echo "==> Generating signed update manifest"

  NOTES_PATH="${CHANGELOG_FILE:-${ROOT}/CHANGELOG.md}"
  NOTES=""
  if [ -f "${NOTES_PATH}" ]; then
    # Pull the first paragraph until the first blank line so the
    # manifest stays small. Customers see this in the Updates UI.
    NOTES="$(awk 'BEGIN{p=0} /^$/{if(p) exit} /./{p=1; print}' "${NOTES_PATH}")"
  fi
  [ -z "${NOTES}" ] && NOTES="CapstoneNMS ${VERSION} release."

  CHECKSUM="$(awk '{print $1}' "${ARCHIVE}.sha256")"
  ARCHIVE_NAME="$(basename "${ARCHIVE}")"
  MANIFEST_OUT="${DIST}/manifest.json"

  php -r '
    [$_, $version, $archiveName, $checksum, $notes, $keyPath, $manifestOut] = $argv;
    $payload = [
        "v"        => 1,
        "latest"   => $version,
        "releases" => [
            [
                "version"         => $version,
                "url"             => "https://update.capstonenms.com/" . $archiveName,
                "sig_url"         => "https://update.capstonenms.com/" . $archiveName . ".sig",
                "checksum_sha256" => $checksum,
                "min_php"         => "8.3",
                "released_at"     => gmdate("c"),
                "notes"           => $notes,
            ],
        ],
    ];
    // Canonical JSON (recursive ksort) so a re-signed identical
    // payload produces byte-identical bytes — matches the keygen.
    $canonicalize = function (array $a) use (&$canonicalize): array {
        ksort($a);
        foreach ($a as $k => $v) if (is_array($v)) $a[$k] = $canonicalize($v);
        return $a;
    };
    $json = json_encode($canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $secret = file_get_contents($keyPath);
    if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Private key wrong length\n"); exit(1);
    }
    $sig = sodium_crypto_sign_detached($json, $secret);

    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
    $envelope = $b64($json) . "." . $b64($sig);
    file_put_contents($manifestOut, $envelope);
    echo "  manifest written: " . basename($manifestOut) . " (" . strlen($envelope) . " bytes)\n";
  ' "${VERSION}" "${ARCHIVE_NAME}" "${CHECKSUM}" "${NOTES}" "${KEY}" "${MANIFEST_OUT}"
fi

# Customer-facing SSH bootstrap script. Tracked in
# scripts/customer/install.sh and copied verbatim — it's manifest-
# driven, so the same file works release-after-release. Customers
# fetch it via:  curl -sSL https://update.capstonenms.com/install.sh | bash
if [ -f "${ROOT}/scripts/customer/install.sh" ]; then
    cp "${ROOT}/scripts/customer/install.sh" "${DIST}/install.sh"
    chmod +x "${DIST}/install.sh"
    echo "==> bundled install.sh"
fi

echo "==> dist/ artifacts:"
ls -la "${DIST}"
