#!/usr/bin/env bash
# Hosting'e elle yüklenecek zip: dist/flx-update-server.zip
# İçinde config.php ve data/ içeriği yoktur; mevcut kurulumun üstüne açılınca ayarlar korunur.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
rm -rf dist && mkdir -p dist
zip -qrX dist/flx-update-server.zip \
  index.php .htaccess config.sample.php README.md lib bin data/.htaccess \
  -x 'bin/build-zip.sh'
echo "dist/flx-update-server.zip"
