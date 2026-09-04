#!/bin/bash
# Build distribution package for WordPress plugin.
# Creates sign-docs/ with only the files needed for WP.
# Run from the plugin root directory.

set -euo pipefail

DIST_DIR="sign-docs"

# Ensure we're in the plugin root
if [ ! -f "sign-docs.php" ]; then
    echo "Error: run from plugin root (where sign-docs.php lives)"
    exit 1
fi

echo "Building distribution in $DIST_DIR/ ..."

# Clean
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# Copy plugin files (languages ships an empty dir, keep it for i18n Domain Path)
cp "sign-docs.php" "$DIST_DIR/sign-docs.php"
cp -r "includes" "$DIST_DIR/includes"
cp -r "assets" "$DIST_DIR/assets"
cp -r "languages" "$DIST_DIR/languages" 2>/dev/null || true

# Drop anything that must not ship (macOS/editor metadata, VCS, source maps)
find "$DIST_DIR" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true
find "$DIST_DIR" -type f \
    \( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.map' \) \
    -delete

# Guard: the main plugin file must exist
if [ ! -f "$DIST_DIR/sign-docs.php" ]; then
    echo "Error: main plugin file missing from $DIST_DIR"
    exit 1
fi

echo "Done. Distribution built in $DIST_DIR/"
echo ""
echo "Contents:"
find "$DIST_DIR" -type f | sort
