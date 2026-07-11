#!/bin/bash
# Build distribution and create a release ZIP for GitHub Releases.
#
# Usage: bash tools/create-release.sh <version-tag>
# Example: bash tools/create-release.sh v0.2.0
#
# Produces: sign-docs.zip  (upload as release asset with this name)

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: $0 <version-tag>"
    echo "Example: $0 v0.2.0"
    exit 1
fi

TAG="$1"
ZIP_NAME="sign-docs.zip"

# Ensure we're in the plugin root
if [ ! -f "sign-docs.php" ]; then
    echo "Error: run from plugin root (where sign-docs.php lives)"
    exit 1
fi

echo "==> Building distribution..."
bash tools/build-dist.sh

echo ""
echo "==> Creating release ZIP: $ZIP_NAME ..."

rm -f "$ZIP_NAME"

cd sign-docs
zip -rq "../$ZIP_NAME" .
cd ..

echo "==> Done: $ZIP_NAME"
echo ""
echo "Next steps:"
echo "  1. Create a GitHub Release with tag '$TAG'"
echo "  2. Upload '$ZIP_NAME' as a release asset (keep the name as-is)"
echo ""
echo "Tip: via GitHub CLI:"
echo "  gh release create '$TAG' '$ZIP_NAME' --title '$TAG' --notes 'Release notes here'"
