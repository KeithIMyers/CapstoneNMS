#!/usr/bin/env bash
set -euo pipefail

# CapstoneNMS — generate customer-facing docs.
#
# Renders docs/*.md templates with current product version + tier
# definitions interpolated, so the README customers see when they
# unzip a release matches what's actually in the build.
#
# Usage: ./scripts/docs.sh

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS="${ROOT}/docs"

VERSION=$(grep "'product_version'" "${ROOT}/source/config/capstone.php" | head -1 | awk -F"'" '{print $4}')
[ -z "${VERSION}" ] && VERSION="dev"

mkdir -p "${ROOT}/build/docs"

echo "==> Rendering docs (version: ${VERSION})"
for f in "${DOCS}"/*.md; do
  [ -f "${f}" ] || continue
  base="$(basename "${f}")"
  sed "s/{{VERSION}}/${VERSION}/g" "${f}" > "${ROOT}/build/docs/${base}"
done

echo "==> docs rendered to build/docs/"
ls -la "${ROOT}/build/docs/" 2>/dev/null || echo "    (no docs/ templates yet)"
