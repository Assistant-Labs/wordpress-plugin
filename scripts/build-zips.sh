#!/usr/bin/env bash
# Package the WordPress plugins as installable zips (WP admin → Plugins → Add
# New → Upload Plugin), one per plugin, into apps/wordpress/dist/.
#
# Each zip holds a single top-level folder named after the plugin slug — the
# layout WordPress and the wordpress.org directory both expect.
#
#   bash scripts/build-zips.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
mkdir -p "$DIST"

for dir in "$ROOT"/plugins/*/; do
	slug="$(basename "$dir")"
	main="$dir$slug.php"
	[ -f "$main" ] || { echo "skip $slug: no $slug.php"; continue; }
	version="$(grep -m1 -E '^\s*\*\s*Version:' "$main" | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
	out="$DIST/$slug-$version.zip"
	rm -f "$out"
	(cd "$ROOT/plugins" && zip -qr "$out" "$slug" \
		-x "*.DS_Store" -x "*/node_modules/*" -x "*/.git*" -x "*/tests/*")
	cp "$out" "$DIST/$slug.zip"
	echo "built $out"
done
