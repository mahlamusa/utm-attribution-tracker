#!/usr/bin/env bash
#
# Install production Composer dependencies into a release stage.
#
# Usage: bin/stage-composer.sh [--keep-source] <stage_dir>
#        bin/stage-composer.sh --slim-only <dir>
#
# vendor/ is not tracked in git, and composer.json/composer.lock are
# export-ignored, so `git archive` stages neither. This copies the manifest
# and lock into the stage and runs `composer install --no-dev` there (never
# touching the working tree's vendor/).
#
# By default the manifest is then replaced with a minimal runtime one and the
# lock dropped, which is what the install zip and wp.org tags ship. Plugin
# Check flags a shipped vendor/ that has no composer.json next to it.
#
# --keep-source keeps the full composer.json and composer.lock, the source
# vendor/ is built from. wp.org trunk ships them so the bundled code can be
# traced and rebuilt from the SVN repository.
#
# --slim-only installs nothing: it only writes the minimal manifest into <dir>
# and drops its lock (used to slim a wp.org tag copied from trunk).

set -euo pipefail

MODE="slim"
case "${1:-}" in
    --keep-source) MODE="source"; shift ;;
    --slim-only)   MODE="slim-only"; shift ;;
esac

STAGE_DIR="${1:?Usage: bin/stage-composer.sh [--keep-source|--slim-only] <stage_dir>}"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"

write_runtime_manifest() {
    echo "==> Writing minimal production composer.json..."
    cat > "$STAGE_DIR/composer.json" <<'EOF'
{
    "name": "mahlamusa/utm-attribution-tracker",
    "description": "UTM Attribution Tracker: self-hosted UTM and first/last-touch attribution for WordPress.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0",
        "woocommerce/action-scheduler": "^3.9"
    }
}
EOF
    rm -f "$STAGE_DIR/composer.lock"
}

if [[ "$MODE" == "slim-only" ]]; then
    write_runtime_manifest
    exit 0
fi

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

if [[ "$MODE" == "source" ]]; then
    echo "==> Keeping full composer.json and composer.lock (source for vendor/)..."
else
    write_runtime_manifest
fi
