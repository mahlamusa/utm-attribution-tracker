#!/usr/bin/env bash
#
# Build a distributable Utmatra release zip.
#
# The plugin has no Composer or npm dependencies, so packaging is just:
#   1. Run build.sh (regenerates the .pot — the only build artifact).
#   2. git archive HEAD -> stage (honors .gitattributes export-ignore, so
#      dev-only files like bin/, package.json, .claude/ never enter the zip).
#   3. Overlay the freshly regenerated languages/*.pot (not committed, so
#      git archive alone wouldn't pick up the latest strings).
#   4. Zip the stage into releases/utm-attribution-tracker-<version>.zip.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"
SLUG="utm-attribution-tracker"
STAGING="$ROOT/.release"
STAGE_DIR="$STAGING/$SLUG"
POT_FILE="languages/${SLUG}.pot"

cd "$ROOT"

VERSION=$(grep -m1 '^ \* Version:' "$ROOT/${SLUG}.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
if [[ -z "$VERSION" ]]; then
    echo "ERROR: Could not read Version from ${SLUG}.php header." >&2
    exit 1
fi
ZIP="$ROOT/releases/${SLUG}-${VERSION}.zip"

cleanup() {
    [[ -d "$STAGING" ]] && rm -rf "$STAGING"
}
trap cleanup EXIT

echo "==> Running build..."
"$SCRIPT_DIR/build.sh"

echo "==> Staging release files (git archive HEAD)..."
rm -rf "$STAGING"
mkdir -p "$STAGE_DIR"
git archive HEAD | tar -x -C "$STAGE_DIR"

echo "==> Overlaying freshly regenerated .pot..."
mkdir -p "$STAGE_DIR/languages"
cp "$ROOT/$POT_FILE" "$STAGE_DIR/$POT_FILE"

echo "==> Writing zip..."
mkdir -p "$ROOT/releases"
rm -f "$ZIP"
( cd "$STAGING" && zip -rq "$ZIP" "$SLUG" )

echo ""
echo "Created: $ZIP"
echo "Size:    $(du -h "$ZIP" | cut -f1)"
