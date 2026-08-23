#!/usr/bin/env bash
# Integration lane: boots a throwaway WordPress (SQLite, plain HTTP) via
# @wp-playground/cli, activates the plugin, and asserts every table and
# identity option exists. Requires Node 20.18+.
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
RESULT_FILE="$DIR/.playground-check-result"

rm -f "$RESULT_FILE"

# run-blueprint swallows runPHP stdout, so the assertion step writes its
# verdict into the mounted plugin directory instead.
OUT=$(npx @wp-playground/cli@latest run-blueprint \
    --blueprint="$DIR/tests/playground/blueprint.json" \
    --mount="$DIR:/wordpress/wp-content/plugins/openyacht" 2>&1) || {
    echo "$OUT"
    echo "playground-check: run-blueprint exited nonzero" >&2
    exit 1
}

if [ -f "$RESULT_FILE" ] && grep -q 'PLAYGROUND-CHECK-OK' "$RESULT_FILE"; then
    rm -f "$RESULT_FILE"
    echo "playground-check: OK"
else
    [ -f "$RESULT_FILE" ] && cat "$RESULT_FILE"
    rm -f "$RESULT_FILE"
    echo "playground-check: assertions failed or produced no result" >&2
    exit 1
fi
