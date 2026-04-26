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
  --exclude='storage/app/private/licensing/*' \
  --exclude='app/Console/Commands/InstallDevLicenseCommand.php' \
  "${SRC}/" "${BUILD}/"

# Sanity check: a dev license must NEVER end up in a customer dist.
if [ -f "${BUILD}/storage/app/private/licensing/license.dat" ]; then
  echo "FATAL: dev license leaked into build/. Aborting." >&2
  exit 1
fi
if [ -f "${BUILD}/app/Console/Commands/InstallDevLicenseCommand.php" ]; then
  echo "FATAL: license:install-dev command leaked into build/. Aborting." >&2
  exit 1
fi

echo "==> composer install --no-dev"
# Build dev boxes that lack the runtime extensions (ext-gd /
# ext-imagick are typically installed via the OS package manager
# with sudo) can still produce a correct vendor/ — composer just
# wants to verify the platform; the resulting tree is identical.
( cd "${BUILD}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-imagick )

if ! command -v npm >/dev/null 2>&1; then
  echo "FATAL: npm not on PATH. The Vite build is part of the dist." >&2
  echo "       Install Node + npm locally (NVM is the simplest path)." >&2
  exit 1
fi
echo "==> npm install + build (frontend assets)"
( cd "${BUILD}" && npm install --silent && npm run build --silent )

echo "==> Applying source protection pass (scripts/protect.php)"
php "${ROOT}/scripts/protect.php" "${BUILD}"

echo "==> build/ ready at: ${BUILD}"
echo "==> Version: ${VERSION}"
