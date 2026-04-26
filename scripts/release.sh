#!/usr/bin/env bash
set -euo pipefail

# CapstoneNMS — full release pipeline.
#
#   build.sh    source/   →  build/  (composer + npm + protect)
#   dist.sh     build/    →  dist/   (zip + sha256 + sig + manifest)
#   deploy.sh   dist/     →  update.capstonenms.com via rsync/SSH
#
# Usage:
#   ./scripts/release.sh 1.0.0
#   ./scripts/release.sh 1.0.0 --dry-run   # build + dist locally, skip deploy
#
# Tip: keep CHANGELOG.md updated — its first paragraph becomes the
# release notes the customer-facing Updates page surfaces.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="${1:-}"
if [ -z "${VERSION}" ]; then
  echo "usage: $0 <version> [--dry-run]" >&2
  exit 1
fi

DRY_RUN=0
if [ "${2:-}" = "--dry-run" ]; then
  DRY_RUN=1
fi

echo "================================================================"
echo "  CapstoneNMS release ${VERSION}"
echo "================================================================"

"${ROOT}/scripts/build.sh" "${VERSION}"
echo ""

"${ROOT}/scripts/dist.sh" "${VERSION}"
echo ""

if [ "${DRY_RUN}" -eq 1 ]; then
  echo "==> --dry-run set; skipping deploy"
  echo "    Inspect dist/ then run: ./scripts/deploy.sh"
  exit 0
fi

"${ROOT}/scripts/deploy.sh"
echo ""

echo "================================================================"
echo "  Released CapstoneNMS ${VERSION}"
echo "  Customers will see the new version in Settings → Updates"
echo "================================================================"
