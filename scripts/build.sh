#!/usr/bin/env bash
set -euo pipefail

# CapstoneNMS — source/ → build/
#
# Phase A: simple copy + composer install --no-dev so build/ is a
# functional Laravel app the dist script can zip. Phase E adds the
# obfuscation + AES encryption pass on the licensing files.
#
# Usage: ./scripts/build.sh [version]
#   version defaults to whatever's in source/config/capstone.php

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${ROOT}/source"
BUILD="${ROOT}/build/capstone-nms"

VERSION="${1:-$(php -r "require '${SRC}/vendor/autoload.php'; echo config('capstone.product_version');" 2>/dev/null || echo "dev")}"

echo "==> Cleaning build/"
rm -rf "${ROOT}/build"
mkdir -p "${BUILD}"

echo "==> Copying source/ → build/"
rsync -a \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='vendor/' \
  --exclude='.env' \
  --exclude='.env.backup' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/app/public/*' \
  --exclude='public/storage' \
  --exclude='public/build/' \
  --exclude='tests/' \
  --exclude='phpunit.xml' \
  --exclude='TODO.md' \
  --exclude='.claude/' \
  "${SRC}/" "${BUILD}/"

echo "==> composer install --no-dev"
( cd "${BUILD}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "==> npm install + build (frontend assets)"
( cd "${BUILD}" && npm install --silent && npm run build --silent )

# Phase E: apply obfuscation + AES encryption on the licensing
# touch-points before we hand off to dist.sh. For now, flag the
# files we'll process so the manifest is visible in source review.
cat > "${BUILD}/_protected_files.txt" <<EOF
app/Services/Licensing/LicenseService.php
app/Http/Middleware/EnforceLicense.php
config/capstone.php
EOF

echo "==> build/ ready at: ${BUILD}"
echo "==> Version: ${VERSION}"
