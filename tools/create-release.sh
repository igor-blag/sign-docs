#!/bin/bash
# Build distribution and create a release ZIP for GitHub Releases.
#
# Usage: bash tools/create-release.sh <version-tag>
# Example: bash tools/create-release.sh v0.3.0
#
# Produces: sign-docs.zip  (upload as release asset with this name)

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: $0 <version-tag>"
    echo "Example: $0 v0.3.0"
    exit 1
fi

TAG="$1"
ZIP_NAME="sign-docs.zip"
DIST_DIR="sign-docs"

# Ensure we're in the plugin root
if [ ! -f "sign-docs.php" ]; then
    echo "Error: run from plugin root (where sign-docs.php lives)"
    exit 1
fi

# Keep the released tag in sync with the plugin version (prevents shipping wrong code).
TAG_VERSION="${TAG#v}"
PLUGIN_VERSION="$(sed -n "s/.*define('SIGN_DOCS_VERSION', '\([^']*\)').*/\1/p" sign-docs.php)"

if [ -z "$PLUGIN_VERSION" ]; then
    echo "Error: could not read SIGN_DOCS_VERSION from sign-docs.php"
    exit 1
fi

if [ "$TAG_VERSION" != "$PLUGIN_VERSION" ]; then
    echo "Error: tag '$TAG' does not match plugin version '$PLUGIN_VERSION'."
    echo "  Re-run with: bash tools/create-release.sh v$PLUGIN_VERSION"
    exit 1
fi

# ZIP tool helper (works on macOS/Linux `zip`, falls back to Windows PowerShell).
zip_dir() {
    local zip_name="$1"
    local dir_name="$2"

    rm -f "$zip_name"

    if command -v zip >/dev/null 2>&1; then
        zip -rq "$zip_name" "$dir_name"
    elif command -v powershell.exe >/dev/null 2>&1; then
        powershell.exe -NoProfile -Command "Compress-Archive -Path '$dir_name' -DestinationPath '$zip_name' -Force"
    else
        echo "Error: no ZIP tool available (need 'zip' or PowerShell)."
        exit 1
    fi
}

echo "==> Building distribution..."
bash tools/build-dist.sh

echo ""
echo "==> Creating release ZIP: $ZIP_NAME ..."
zip_dir "$ZIP_NAME" "$DIST_DIR"

# Verify the archive has the WordPress-expected root folder.
if command -v unzip >/dev/null 2>&1; then
    if ! unzip -l "$ZIP_NAME" | grep -q "sign-docs/sign-docs.php"; then
        echo "Error: '$ZIP_NAME' does not contain sign-docs/sign-docs.php."
        exit 1
    fi
fi

echo "==> Done: $ZIP_NAME"
echo ""
echo "Next steps:"
echo "  1. Create a GitHub Release with tag '$TAG'"
echo "  2. Upload '$ZIP_NAME' as a release asset (keep the name as-is)"
echo ""
echo "Tip: via GitHub CLI:"
echo "  gh release create '$TAG' '$ZIP_NAME' --title '$TAG' --notes 'Release notes here'"
