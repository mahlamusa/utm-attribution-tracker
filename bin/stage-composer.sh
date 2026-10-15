#!/usr/bin/env bash
#
# Install production Composer dependencies into a release stage.
#
# Usage: bin/stage-composer.sh <stage_dir>
#
# vendor/ is not tracked in git, and composer.json/composer.lock are
# export-ignored, so `git archive` stages neither. This copies the manifest
# and lock into the stage, runs `composer install --no-dev` there (never
# touching the working tree's vendor/), then replaces the manifest with a
# minimal runtime one and drops the lock. Plugin Check flags a shipped
# vendor/ that has no composer.json next to it.

set -euo pipefail

STAGE_DIR="${1:?Usage: bin/stage-composer.sh <stage_dir>}"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"

if ! command -v composer >/dev/null 2>&1; then
    echo "ERROR: composer is required to bundle Action Scheduler into the release." >&2
    exit 1
fi

echo "==> Installing production Composer dependencies into the stage..."
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE_DIR/"
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --working-dir="$STAGE_DIR" --quiet

if [[ ! -f "$STAGE_DIR/vendor/woocommerce/action-scheduler/action-scheduler.php" ]]; then
    echo "ERROR: Action Scheduler is missing from the staged vendor/." >&2
    exit 1
fi

echo "==> Writing minimal production composer.json..."
cat > "$STAGE_DIR/composer.json" <<'EOF'
{
    "name": "mahlamusa/utm-attribution-tracker",
    "description": "Utmatra - UTM Attribution and Tracking: self-hosted UTM and first/last-touch attribution for WordPress.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0",
        "woocommerce/action-scheduler": "^3.9"
    }
}
EOF
rm -f "$STAGE_DIR/composer.lock"
