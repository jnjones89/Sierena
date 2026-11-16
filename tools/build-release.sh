#!/usr/bin/env bash
# Builds the two files you upload:
#   dist/workflow-shadow-audit-<version>.zip  -> Chrome Web Store (Developer Dashboard → Package → Upload)
#   dist/business-<version>.zip                -> Hostinger File Manager (public_html) → Extract
# Usage: tools/build-release.sh [business-url]   (default https://sirenaflow.com/business)
set -euo pipefail
cd "$(dirname "$0")/.."
URL="${1:-https://sirenaflow.com/business}"
URL="${URL%/}"
VER=$(python3 -c "import json;print(json.load(open('workflow-shadow-audit/manifest.json'))['version'])")
rm -rf dist && mkdir -p dist/tmp

# Extension: point it at your server.
cp -r workflow-shadow-audit dist/tmp/ext
sed -i.bak "s#^const BUSINESS_BASE_URL = '.*';#const BUSINESS_BASE_URL = '$URL';#" dist/tmp/ext/popup.js && rm dist/tmp/ext/popup.js.bak
grep -q "const BUSINESS_BASE_URL = '$URL';" dist/tmp/ext/popup.js
(cd dist/tmp/ext && zip -qr "../../workflow-shadow-audit-$VER.zip" . -x '.*')

# Server: the business folder, without local secrets or test databases.
(zip -qr "dist/business-$VER.zip" business -x 'business/includes/config.php' '*.sqlite' '*/.DS_Store')
rm -rf dist/tmp
ls -lh dist
echo "Extension points to: $URL"
