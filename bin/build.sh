#!/usr/bin/env bash
#
# Build step for Utmatra.
#
# The plugin ships plain PHP/CSS/JS with no Composer or npm build step, so
# the only build artifact is the translations template (.pot). Run this
# before packaging a release, or any time translatable strings change.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"
TEXT_DOMAIN="utm-attribution-tracker"

cd "$ROOT"

echo "==> Regenerating translations template (.pot)..."
# Try the LocalWP wrapper first (so this works inside ~/Local Sites/), then the
# global wp-cli, then a vendor/bin/wp if one is ever pulled in.
if command -v wpp >/dev/null 2>&1; then
    POT_CMD="wpp"
elif command -v wp >/dev/null 2>&1; then
    POT_CMD="wp"
elif [[ -x "$ROOT/vendor/bin/wp" ]]; then
    POT_CMD="$ROOT/vendor/bin/wp"
else
    echo "ERROR: Neither 'wpp', 'wp', nor 'vendor/bin/wp' is available — install WP-CLI before running build." >&2
    exit 1
fi

mkdir -p "$ROOT/languages"
$POT_CMD i18n make-pot "$ROOT" "$ROOT/languages/${TEXT_DOMAIN}.pot" \
    --domain="${TEXT_DOMAIN}" \
    --slug="${TEXT_DOMAIN}" \
    --exclude=bin,releases,.release,.claude

echo "==> Build complete."
