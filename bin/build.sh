#!/usr/bin/env bash
#
# Build a distributable plugin zip: runtime files only, production vendor/,
# top-level openyacht/ folder so WP's plugin uploader installs it cleanly.
# Output lands next to the plugin dir: wp-content/plugins/openyacht-<version>.zip
#
# Usage: bin/build.sh   (from anywhere; paths resolve from the script location)

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: \([0-9][0-9a-zA-Z.-]*\)$/\1/p' "$PLUGIN_DIR/openyacht.php")"

if [[ -z "$VERSION" ]]; then
    echo "Could not read the Version header from openyacht.php" >&2
    exit 1
fi

CONST_VERSION="$(sed -n "s/^define('OPENYACHT_VERSION', '\([^']*\)');$/\1/p" "$PLUGIN_DIR/openyacht.php")"
if [[ "$CONST_VERSION" != "$VERSION" ]]; then
    echo "Version header ($VERSION) and OPENYACHT_VERSION constant ($CONST_VERSION) disagree — fix before building" >&2
    exit 1
fi

OUT_ZIP="$(dirname "$PLUGIN_DIR")/openyacht-$VERSION.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "Staging openyacht $VERSION ..."
rsync -a "$PLUGIN_DIR/" "$STAGE/openyacht/" \
    --exclude '.git/' \
    --exclude '.github/' \
    --exclude '.impeccable/' \
    --exclude '.phpunit.cache/' \
    --exclude 'node_modules/' \
    --exclude '/vendor/' \
    --exclude 'tests/' \
    --exclude 'bin/' \
    --exclude 'assets/admin/editor.src.css' \
    --exclude '.gitignore' \
    --exclude '.gitattributes' \
    --exclude '.php-cs-fixer.dist.php' \
    --exclude '.php-cs-fixer.cache' \
    --exclude 'phpstan.neon.dist' \
    --exclude 'phpunit.xml.dist' \
    --exclude 'package.json' \
    --exclude 'package-lock.json' \
    --exclude 'DESIGN.md' \
    --exclude 'PRODUCT.md'

echo "Installing production dependencies ..."
composer install \
    --working-dir="$STAGE/openyacht" \
    --no-dev --optimize-autoloader --no-interaction --quiet
rm -f "$STAGE/openyacht/composer.lock"

echo "Zipping ..."
rm -f "$OUT_ZIP"
php -d memory_limit=256M -r '
    [$stage, $out] = [$argv[1], $argv[2]];
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Cannot create $out\n");
        exit(1);
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($stage) + 1));
        }
    }
    $zip->close();
' "$STAGE" "$OUT_ZIP"

SIZE="$(du -h "$OUT_ZIP" | cut -f1)"
echo "Built $OUT_ZIP ($SIZE)"
