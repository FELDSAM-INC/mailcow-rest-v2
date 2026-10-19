#!/usr/bin/env bash
#
# Parity goldens of the quarantine item details (feature 006 T008, research R5, R22): runs the
# parsing part of legacy inc/ajax/qitem_details.php (scripts/legacy/harness/qitem-details.php) in
# the tag's php-fpm image with the tag's data/web/inc/lib/vendor over every message fixture
# tests/fixtures/quarantine/messages/*.eml, and writes tests/fixtures/quarantine/legacy/<name>.json
# (recipients, header_from, text_plain, text_html, subject, attachments with name, type, size and
# SHA-256). The envelope recipient is alice@example.org. tests/Unit/Quarantine/
# QuarantineMessageParserTest.php compares the API's parsed content with them.
#
#   scripts/legacy/generate-qitem-goldens.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code); PHPFPM_IMAGE overrides
# the image (default: the one the tag's docker-compose.yml names). Runs on the host with Docker.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"
MESSAGES="$ROOT/tests/fixtures/quarantine/messages"
OUT="$ROOT/tests/fixtures/quarantine/legacy"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

IMAGE="${PHPFPM_IMAGE:-$(git -C "$MAILCOW_REPO" show "$TAG:docker-compose.yml" | sed -n 's/^ *image: *\(ghcr.io\/mailcow\/phpfpm:[^ ]*\).*/\1/p' | head -1)}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
git -C "$MAILCOW_REPO" archive "$TAG" data/web/inc/lib/vendor | tar -x -C "$WORK"
chmod -R a+rX "$WORK"

mkdir -p "$OUT"

for message in "$MESSAGES"/*.eml; do
    name="$(basename "$message" .eml)"
    docker run --rm --network none --user 82:82 --entrypoint php \
        -v "$WORK/data/web/inc/lib/vendor:/legacy/vendor:ro" \
        -v "$ROOT/scripts/legacy/harness/qitem-details.php:/legacy/harness.php:ro" \
        -v "$MESSAGES:/legacy/messages:ro" \
        "$IMAGE" -d error_reporting=0 /legacy/harness.php /legacy/vendor/autoload.php "/legacy/messages/$name.eml" alice@example.org \
        > "$OUT/$name.json"
    echo "$name"
done
