#!/bin/bash
#
# bin/wp-org-publish.sh — End-to-end publish workflow for UTM & Attribution Tracker
# releases to the WordPress.org plugin repository.
#
# Run via: npm run wporg:publish        (uses Stable tag from readme.txt)
#          npm run wporg:publish -- 1.1.0    (explicit version override)
#
# There is no Composer vendor/ to
# build and no npm/vite dist/ to compile — this plugin ships plain PHP/CSS/JS
# as written, with Chart.js vendored directly in assets/js/vendor/. The only
# build artifact is the translations .pot, regenerated fresh and overlaid
# into the stage (it isn't committed, so a plain `git archive` wouldn't pick
# up the latest strings). Since .gitattributes already keeps every dev-only
# path (bin/, package.json, .claude/, etc.) out of `git archive` entirely,
# trunk/ and tags/<version>/ end up identical — no separate tag-slimming pass
# is needed.
#
# Pipeline:
#   1. Resolve + validate version, assets, working tree.
#   2. Refresh the .pot (bin/build.sh).
#   3. Check out / refresh the wp.org SVN working copy at /tmp/<slug>.
#   4. git archive HEAD -> /tmp/<slug>-clean, overlay the freshly built .pot,
#      rsync the stage into trunk/. Sync .wordpress.org/ into assets/.
#   5. svn cp trunk -> tags/<version>.
#   6. Print `svn status` and the exact `svn ci` command. STOPS HERE — you run
#      `svn commit` so the wp.org credential prompt is interactive.
#
# Requires: bash 4+, git, svn (>= 1.7), rsync, and a WP-CLI binary
# (wpp / wp / vendor/bin/wp — searched in that order).
#

set -e

SLUG="utm-attribution-tracker"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
GITPATH="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"
ASSETS_DIR=".wordpress.org"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}/"
SVN_DIR="/tmp/${SLUG}"
TMP_DIR="/tmp/${SLUG}-clean"
POT_FILE="languages/${SLUG}.pot"

# Default wp.org committer username. Override with `WP_ORG_USERNAME=other npm run wporg:publish`.
WP_ORG_USERNAME="${WP_ORG_USERNAME:-mahlamusa}"

# Allowed-dirty paths in `git status --porcelain` output. bin/build.sh
# regenerates the .pot in the working tree, so that path is tolerated.
# Anything else is an unrelated change the user must commit or stash first.
DIRTY_ALLOW_REGEX="^.. ${POT_FILE//\//\\/}$"

# ---------------------------------------------------------------------------
# 1. Resolve version.
# ---------------------------------------------------------------------------
if [[ -n "${1:-}" ]]; then
    VERSION="$1"
else
    VERSION="$( grep "^Stable tag:" "$GITPATH/readme.txt" | awk '{print $NF}' | tr -d '\r' )"
fi

if [[ -z "$VERSION" ]]; then
    echo "ERROR: version not provided and Stable tag not found in readme.txt" >&2
    exit 1
fi

echo "==> Slug:     $SLUG"
echo "==> Version:  $VERSION"
echo "==> Repo:     $GITPATH"
echo "==> SVN URL:  $SVN_URL"
echo "==> SVN WC:   $SVN_DIR"

# ---------------------------------------------------------------------------
# 2. Sanity checks.
# ---------------------------------------------------------------------------
if [[ ! -d "$GITPATH/$ASSETS_DIR" ]]; then
    echo "ERROR: wp.org assets directory not found at $GITPATH/$ASSETS_DIR" >&2
    echo "       Add banner/icon/screenshot images before publishing." >&2
    exit 1
fi

if ! grep -q "^ \* Version: *${VERSION}\$" "$GITPATH/${SLUG}.php"; then
    echo "ERROR: ${SLUG}.php Plugin Header Version: line does not match ${VERSION}" >&2
    echo "       Update it (and UTM_ATTRIBUTION_TRACKER_VERSION below it) before re-running." >&2
    exit 1
fi

# Refuse to proceed if the working tree has changes outside the regenerated
# .pot. Nothing here is committed, but an unexpected change signals the tree
# isn't in a clean release state.
UNRELATED_DIRTY="$( git -C "$GITPATH" status --porcelain | { grep -vE "$DIRTY_ALLOW_REGEX" || true; } )"
if [[ -n "$UNRELATED_DIRTY" ]]; then
    echo "ERROR: working tree has changes outside ${POT_FILE}:" >&2
    echo "$UNRELATED_DIRTY" >&2
    echo "" >&2
    echo "Commit, stash, or revert them, then re-run." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Pick a WP-CLI binary for the .pot regeneration step.
# ---------------------------------------------------------------------------
if command -v wpp >/dev/null 2>&1; then
    POT_CMD="wpp"
elif command -v wp >/dev/null 2>&1; then
    POT_CMD="wp"
elif [[ -x "$GITPATH/vendor/bin/wp" ]]; then
    POT_CMD="$GITPATH/vendor/bin/wp"
else
    echo "ERROR: WP-CLI not found (need 'wpp', 'wp', or vendor/bin/wp)." >&2
    exit 1
fi
echo "==> WP-CLI:   $POT_CMD"

# ---------------------------------------------------------------------------
# 4. Refresh the .pot in the working tree. Overlaid into the stage below;
#    not committed.
# ---------------------------------------------------------------------------
echo "==> Regenerating translations template (.pot)..."
( cd "$GITPATH" && "$POT_CMD" i18n make-pot \
    "$GITPATH" "$GITPATH/$POT_FILE" \
    --domain="${SLUG}" \
    --slug="${SLUG}" \
    --exclude="bin,releases,.release,.claude" )

# ---------------------------------------------------------------------------
# 5. Set up the SVN working copy. --depth immediates keeps the checkout cheap;
#    we expand only the directories we touch.
# ---------------------------------------------------------------------------
echo "==> Setting up SVN working copy..."
if [[ -d "$SVN_DIR/.svn" ]]; then
    echo "    reusing existing working copy"
    svn cleanup "$SVN_DIR" || true
    svn revert -R "$SVN_DIR" >/dev/null 2>&1 || true
else
    echo "    fresh checkout (--depth immediates)"
    svn checkout --depth immediates "$SVN_URL" "$SVN_DIR"
fi
cd "$SVN_DIR"
svn update --set-depth infinity assets
svn update --set-depth infinity trunk
svn update --depth immediates tags

if [[ -d "$SVN_DIR/tags/$VERSION" ]]; then
    echo "ERROR: tags/$VERSION already exists in $SVN_URL." >&2
    echo "       wp.org release tags are immutable. Bump the version" >&2
    echo "       (readme.txt 'Stable tag:' + ${SLUG}.php header)" >&2
    echo "       and re-run." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 6. Assemble the trunk payload in a clean staging dir: git archive (honors
#    .gitattributes export-ignore, so dev-only files never enter the stage)
#    plus the freshly regenerated .pot.
# ---------------------------------------------------------------------------
echo "==> Exporting git HEAD to $TMP_DIR/ ..."
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
git -C "$GITPATH" archive HEAD | tar x --directory="$TMP_DIR"

echo "==> Overlaying freshly regenerated .pot..."
mkdir -p "$TMP_DIR/languages"
cp "$GITPATH/$POT_FILE" "$TMP_DIR/$POT_FILE"

# ---------------------------------------------------------------------------
# 7. Sync trunk/ and assets/. rsync -rc uses content checksums (not just mtime)
#    for accurate change detection; --delete catches removals so svn status
#    reports them as missing (!), which we convert to svn rm.
# ---------------------------------------------------------------------------
echo "==> Syncing trunk/ from $TMP_DIR/ ..."
rsync -rc "$TMP_DIR/" "$SVN_DIR/trunk/" --delete

echo "==> Syncing assets/ from $ASSETS_DIR/ ..."
rsync -rc "$GITPATH/$ASSETS_DIR/" "$SVN_DIR/assets/" --delete

# ---------------------------------------------------------------------------
# 8. Reconcile add/rm with SVN.
# ---------------------------------------------------------------------------
echo "==> Reconciling svn add/rm..."
cd "$SVN_DIR"
svn add . --force >/dev/null
MISSING="$( svn status | { grep '^!' || true; } | awk '{ $1=""; sub(/^ +/, ""); print }' )"
if [[ -n "$MISSING" ]]; then
    while IFS= read -r path; do
        [[ -n "$path" ]] && svn rm "$path" >/dev/null
    done <<< "$MISSING"
fi

# ---------------------------------------------------------------------------
# 9. Create tags/<version> as a working-copy copy of trunk. There is
#    nothing left to strip — .gitattributes already kept every
#    dev-only path out of trunk itself, so the tag ships identical content.
# ---------------------------------------------------------------------------
echo "==> Creating tags/$VERSION as a copy of trunk/..."
svn cp "$SVN_DIR/trunk" "$SVN_DIR/tags/$VERSION"

# ---------------------------------------------------------------------------
# 10. Summary + commit instructions.
# ---------------------------------------------------------------------------
echo ""
echo "==> Pending SVN changes (review before commit):"
echo "------------------------------------------------------------"
svn status
echo "------------------------------------------------------------"
echo ""
echo "NEXT STEPS:"
echo ""
echo "    cd \"$SVN_DIR\""
echo "    svn ci -m \"Release $VERSION\" --username $WP_ORG_USERNAME"
echo ""
echo "    SVN will prompt for your wp.org password."
echo "    After commit, allow ~15 minutes for wp.org to refresh."
echo ""
echo "==> Working copy ready at $SVN_DIR"
