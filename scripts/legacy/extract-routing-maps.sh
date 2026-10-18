#!/usr/bin/env bash
#
# Copies the routing lookups of an upstream mailcow-dockerized tag into
# tests/fixtures/legacy/routing-maps/<tag>/<name>.sql (feature 009 T005): the `query = …` of the
# Postfix SQL maps that postfix.sh writes, and the two BCC queries of Rspamd's bcc.php. The parity
# tests run them against rows written through the API, so that the stored data routes, rewrites
# and copies mail as legacy's does (SC-002).
#
#   scripts/legacy/extract-routing-maps.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code). Only tags are accepted.
# Postfix placeholders (%s, %d, %u) and the `%%` escape are kept as written; bcc.php's
# `:local_dest` parameter is kept as well.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"
POSTFIX="data/Dockerfiles/postfix/postfix.sh"
BCC="data/conf/rspamd/dynmaps/bcc.php"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

git -C "$MAILCOW_REPO" show "$TAG:$POSTFIX" | tr -d '\r' >"$WORK/postfix.sh"
git -C "$MAILCOW_REPO" show "$TAG:$BCC" | tr -d '\r' >"$WORK/bcc.php"
POSTFIX_SHA="$(git -C "$MAILCOW_REPO" show "$TAG:$POSTFIX" | sha256sum | cut -d' ' -f1)"
BCC_SHA="$(git -C "$MAILCOW_REPO" show "$TAG:$BCC" | sha256sum | cut -d' ' -f1)"

OUT="$ROOT/tests/fixtures/legacy/routing-maps/$TAG"
mkdir -p "$OUT"

header() {
    echo "-- Extracted by scripts/legacy/extract-routing-maps.sh from mailcow-dockerized $TAG."
    echo "-- Source: $1 (SHA-256 $2)."
    echo "-- Do not edit: regenerate with the script."
}

# The query of one map: from "query = " after "cat <<EOF > …/<map>" to the closing "EOF".
postfix_map() {
    local map="$1"
    local query
    query="$(awk -v target="/opt/postfix/conf/sql/$map.cf" '
        $0 ~ "^cat <<EOF > " && index($0, target) { inside = 1; next }
        inside && /^EOF$/ { exit }
        inside && /^query = / { querying = 1; sub(/^query = /, "") }
        inside && querying { print }
    ' "$WORK/postfix.sh")"

    if [[ -z "$query" ]]; then
        echo "error: $map not found in $TAG:$POSTFIX" >&2
        exit 1
    fi

    { header "$POSTFIX, $map.cf" "$POSTFIX_SHA"; echo "$query"; } >"$OUT/$map.sql"
}

for map in \
    mysql_sender_dependent_default_transport_maps \
    mysql_sasl_passwd_maps_sender_dependent \
    mysql_sasl_passwd_maps_transport_maps \
    mysql_transport_maps \
    mysql_mbr_access_maps \
    mysql_tls_policy_override_maps \
    mysql_recipient_canonical_maps; do
    postfix_map "$map"
done

# The two prepared statements of bcc.php, in order: recipient (`rcpt`) maps, then sender maps.
bcc_query() {
    local type="$1" name="$2"
    local query
    query="$(grep -o "SELECT \`bcc_dest\` FROM \`bcc_maps\` WHERE \`type\` = '$type' AND \`local_dest\` = :local_dest AND \`active\` = '1'" "$WORK/bcc.php" | head -n1)"

    if [[ -z "$query" ]]; then
        echo "error: the $type query was not found in $TAG:$BCC" >&2
        exit 1
    fi

    { header "$BCC ($type)" "$BCC_SHA"; echo "$query"; } >"$OUT/$name.sql"
}

bcc_query rcpt bcc_maps_recipient
bcc_query sender bcc_maps_sender

echo "Wrote $(find "$OUT" -name '*.sql' | wc -l) queries to ${OUT#"$ROOT/"}"
