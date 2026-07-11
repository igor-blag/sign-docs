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

# Create directory structure
mkdir -p "$DIST_DIR"

# Copy plugin files
cp "sign-docs.php" "$DIST_DIR/sign-docs.php"
cp -r "includes" "$DIST_DIR/includes"
cp -r "assets" "$DIST_DIR/assets"

# Remove macOS metadata files
find "$DIST_DIR" -name '.DS_Store' -delete

echo "Done. Distribution built in $DIST_DIR/"
echo ""
echo "Contents:"
find "$DIST_DIR" -type f | sort
