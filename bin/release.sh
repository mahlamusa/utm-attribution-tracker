#!/usr/bin/env bash
#
# Build a distributable UTM & Attribution Tracker release zip.
#
# The plugin has no Composer or npm dependencies, so packaging is just:
#   1. Run build.sh (regenerates the .pot).
#   2. Stage a clean copy of the plugin, excluding dev-only files.
#   3. Zip the stage into releases/utm-attribution-tracker-<version>.zip.
#
# Unlike the companion SMTP plugin's release script, this one does not use
# `git archive` — this plugin directory is not a git repository — so the
# stage is built with rsync against an explicit exclude list instead.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"
SLUG="utm-attribution-tracker"
STAGING="$ROOT/.release"
STAGE_DIR="$STAGING/$SLUG"

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

echo "==> Staging release files..."
rm -rf "$STAGING"
mkdir -p "$STAGE_DIR"
rsync -a "$ROOT/" "$STAGE_DIR/" \
    --exclude=".git" \
    --exclude=".claude" \
    --exclude=".release" \
    --exclude="bin" \
    --exclude="releases" \
    --exclude=".gitignore" \
    --exclude=".DS_Store" \
    --exclude="package.json"

echo "==> Writing zip..."
mkdir -p "$ROOT/releases"
rm -f "$ZIP"
( cd "$STAGING" && zip -rq "$ZIP" "$SLUG" )

echo ""
echo "Created: $ZIP"
echo "Size:    $(du -h "$ZIP" | cut -f1)"
