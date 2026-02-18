#!/usr/bin/env bash
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="str-direct-booking"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
OUTPUT="$PARENT_DIR/$PLUGIN_SLUG.zip"
STAGING="$PARENT_DIR/$PLUGIN_SLUG"

echo "→ Installing PHP dependencies (no dev)..."
composer install --no-dev --optimize-autoloader --working-dir="$PLUGIN_DIR"

echo "→ Staging plugin files to: $STAGING"
rm -rf "$STAGING"
mkdir -p "$STAGING"

rsync -a --quiet \
  --exclude=".git" \
  --exclude=".gitignore" \
  --exclude="node_modules" \
  --exclude="src" \
  --exclude="webpack.config.js" \
  --exclude="package.json" \
  --exclude="package-lock.json" \
  --exclude="bin" \
  --exclude=".DS_Store" \
  "$PLUGIN_DIR/" "$STAGING/"

echo "→ Creating zip: $OUTPUT"
rm -f "$OUTPUT"
cd "$PARENT_DIR"
zip -r "$OUTPUT" "$PLUGIN_SLUG" --exclude "*.DS_Store"

echo "→ Cleaning up staging directory..."
rm -rf "$STAGING"

echo ""
echo "✓ Plugin zip created: $OUTPUT"
echo "  Upload via: WordPress Admin → Plugins → Add New → Upload Plugin"
