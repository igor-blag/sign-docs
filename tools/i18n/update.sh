#!/bin/bash
# Regenerate Sign Docs translation artifacts from sources.
#
#   languages/sign-docs.pot                        - template (msgid only)
#   languages/sign-docs-ru_RU.po                   - canonical Russian source of truth
#   languages/sign-docs-ru_RU.mo                   - binary catalog for PHP strings
#   languages/sign-docs-ru_RU.l10n.php             - PHP catalog for PHP strings (WP 6.5+ preferred)
#   languages/sign-docs-ru_RU-<md5>.json           - JS script translations (wp_set_script_translations)
#
# Edit languages/sign-docs-ru_RU.po directly, then run this script.
# Requires Python 3.8+. No third-party packages.
#
# Run from the plugin root:
#   bash tools/i18n/update.sh

set -euo pipefail

if [ ! -f "sign-docs.php" ]; then
    echo "Error: run from plugin root (where sign-docs.php lives)"
    exit 1
fi

echo "Extracting msgids into languages/sign-docs.pot ..."
python3 tools/i18n/extract_pot.py

echo "Compiling .mo and JS JSON from languages/sign-docs-ru_RU.po ..."
python3 tools/i18n/build.py

echo ""
echo "Done. Remember to commit the updated files under languages/."
