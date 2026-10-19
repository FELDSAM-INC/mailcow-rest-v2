#!/usr/bin/env bash
#
# Extracts the strings of a released quarantine item's "attachment" format from an upstream
# mailcow-dockerized tag (feature 006 T006, research R3): `quarantine.release_subject` and
# `quarantine.release_body` of every data/web/lang/lang.*.json, written to
# resources/legacy/quarantine-release-strings.json as {"_source": <tag>, "<language>": {"subject",
# "body"}} with the languages sorted. A language without either string is left out (the API then
# falls back to en-gb, as legacy's language merge does). It also copies the tag's default
# notification template data/assets/templates/quarantine.tpl to resources/legacy/quarantine.tpl,
# the copy the API uses when mailcow's template directory is not mounted.
#
#   scripts/legacy/extract-quarantine-release-strings.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code). Only tags are accepted.
# Runs on the host (git and python3); the checkout is outside the dev container's mount.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"
OUT="$ROOT/resources/legacy"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

git -C "$MAILCOW_REPO" ls-tree --name-only "$TAG" data/web/lang/ | grep -E '/lang\.[a-z]{2}-[a-z]{2}\.json$' | while read -r path; do
    git -C "$MAILCOW_REPO" show "$TAG:$path" > "$WORK/$(basename "$path")"
done

mkdir -p "$OUT"

python3 -I - "$TAG" "$WORK" "$OUT/quarantine-release-strings.json" <<'PY'
import json, os, re, sys

tag, work, out = sys.argv[1], sys.argv[2], sys.argv[3]
strings = {}
for name in sorted(os.listdir(work)):
    language = re.match(r'^lang\.([a-z]{2}-[a-z]{2})\.json$', name).group(1)
    with open(os.path.join(work, name), encoding='utf-8') as f:
        quarantine = json.load(f).get('quarantine', {})
    subject, body = quarantine.get('release_subject'), quarantine.get('release_body')
    if isinstance(subject, str) and isinstance(body, str):
        strings[language] = {'subject': subject, 'body': body}

with open(out, 'w', encoding='utf-8') as f:
    json.dump({'_source': tag, **strings}, f, ensure_ascii=False, indent=4)
    f.write('\n')
print(f'{len(strings)} languages written to {out}')
PY

git -C "$MAILCOW_REPO" show "$TAG:data/assets/templates/quarantine.tpl" > "$OUT/quarantine.tpl"
echo "default template written to $OUT/quarantine.tpl"
