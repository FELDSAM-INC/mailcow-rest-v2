#!/usr/bin/env bash
#
# Copies the mailcow files that feature 013 reads and writes (research R9, R13, R14, R22) from an
# upstream mailcow-dockerized tag, unmodified, into the test fixtures, with a SOURCE file per
# directory naming the tag and the SHA-256 of each file:
#
#   tests/fixtures/mailcow/rspamd-custom/*.map           data/conf/rspamd/custom/*.map
#   tests/fixtures/mailcow/global-sieve/global_sieve_*   data/conf/dovecot/global_sieve_{before,after}
#   tests/fixtures/mailcow-tpls/quota.tpl                data/assets/templates/quota.tpl
#   tests/fixtures/mailcow-inc/presets/{rspamd,sieve}/   data/web/inc/presets/{rspamd,sieve}/*.yml
#   tests/fixtures/mailcow/lang/lang.{en-gb,de-de}.json  data/web/lang/
#
# The password reset templates of tests/fixtures/mailcow-tpls are feature 005's copies.
#
#   scripts/legacy/copy-settings-fixtures.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code). Only tags are accepted, so
# that a fork branch never ends up in the fixtures.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

# copy <output directory> <path in the tag>...: copies the files and appends them to SOURCE.
copy() {
    local out="$ROOT/$1"
    shift
    mkdir -p "$out"

    if [[ ! -f "$out/SOURCE" ]]; then
        {
            echo "Files of mailcow-dockerized, copied unmodified from tag $TAG"
            echo "by scripts/legacy/copy-settings-fixtures.sh $TAG. Do not edit: regenerate instead."
            echo "mailcow-dockerized is distributed under the GNU General Public License, version 3."
            echo
            echo "SHA-256 of each file:"
        } >"$out/SOURCE"
    fi

    local path name
    for path in "$@"; do
        name="$(basename "$path")"
        git -C "$MAILCOW_REPO" show "$TAG:$path" >"$out/$name"
        echo "$(sha256sum "$out/$name" | cut -d' ' -f1)  $name  ($path)" >>"$out/SOURCE"
    done
}

list() {
    git -C "$MAILCOW_REPO" ls-tree --name-only "$TAG" "$1/" | grep -E "$2"
}

rm -rf "$ROOT/tests/fixtures/mailcow" "$ROOT/tests/fixtures/mailcow-inc/presets"

mapfile -t maps < <(list data/conf/rspamd/custom '\.map$')
copy tests/fixtures/mailcow/rspamd-custom "${maps[@]}"
copy tests/fixtures/mailcow/global-sieve data/conf/dovecot/global_sieve_before data/conf/dovecot/global_sieve_after
copy tests/fixtures/mailcow/lang data/web/lang/lang.en-gb.json data/web/lang/lang.de-de.json

mapfile -t rspamd < <(list data/web/inc/presets/rspamd '\.yml$')
copy tests/fixtures/mailcow-inc/presets/rspamd "${rspamd[@]}"
mapfile -t sieve < <(list data/web/inc/presets/sieve '\.yml$')
copy tests/fixtures/mailcow-inc/presets/sieve "${sieve[@]}"

# The quota template joins feature 005's template copies; its checksum is recorded next to them.
git -C "$MAILCOW_REPO" show "$TAG:data/assets/templates/quota.tpl" >"$ROOT/tests/fixtures/mailcow-tpls/quota.tpl"
echo "$(sha256sum "$ROOT/tests/fixtures/mailcow-tpls/quota.tpl" | cut -d' ' -f1)  quota.tpl  (data/assets/templates/quota.tpl, $TAG)" \
    >"$ROOT/tests/fixtures/mailcow-tpls/quota.tpl.SOURCE"

echo "copied the feature 013 fixtures of $TAG" >&2
