#!/usr/bin/env bash
#
# Renders the template body of legacy mobileconfig.php of an upstream mailcow-dockerized tag with
# fixed values into tests/fixtures/legacy/mobileconfig/<tag>-email.plist and <tag>-full.plist
# (feature 005 T120). The template part (from `echo '<?xml`) runs unmodified; the values it reads
# are set beforehand, and getGUID() answers a fixed UUID. See the README next to the fixtures.
#
#   scripts/legacy/render-mobileconfig-fixtures.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code). Only tags are accepted.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"
OUT="$ROOT/tests/fixtures/legacy/mobileconfig"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

TEMPLATE="$(mktemp)"
trap 'rm -f "$TEMPLATE"' EXIT
{ echo '<?php'; git -C "$MAILCOW_REPO" show "$TAG:data/web/mobileconfig.php" | sed -n "/^echo '<?xml/,\$p"; } > "$TEMPLATE"

mkdir -p "$OUT"

for variant in email full; do
    php -d error_reporting=0 -r '
        $email = "anna@example.org";
        $displayname = htmlspecialchars("Anna & <B>", ENT_NOQUOTES);
        $UI_TEXTS = ["main_name" => "mailcow UI"];
        $identifier = implode(".", array_reverse(preg_split("/(@|\.)/", $email))) . ".appleprofile." . preg_replace("/[^a-zA-Z0-9]+/", "", $UI_TEXTS["main_name"]);
        $onlyEmailAccount = $argv[2] === "email";
        $description = $onlyEmailAccount ? "IMAP" : "IMAP, CalDAV, CardDAV";
        $app_password = false;
        $autodiscover_config = [
            "imap" => ["server" => "mail.example.org", "port" => 993],
            "smtp" => ["server" => "mail.example.org", "port" => 465],
            "caldav" => ["server" => "mail.example.org", "port" => 443],
            "carddav" => ["server" => "mail.example.org", "port" => 443],
        ];
        function getGUID() { return "00000000-0000-4000-8000-000000000000"; }
        include $argv[1];
    ' "$TEMPLATE" "$variant" > "$OUT/$TAG-$variant.plist"
done
