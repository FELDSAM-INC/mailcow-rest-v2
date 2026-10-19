#!/usr/bin/env bash
#
# Parity goldens of the notification template check (feature 006 T009, research R7, SC-011):
# parses and renders every template fixture tests/fixtures/quarantine/templates/*.tpl with the
# legacy notifier's engine (Jinja2's SandboxedEnvironment and html2text, exactly the calls of
# quarantine_notify.py, scripts/legacy/harness/quarantine-template-golden.py) in the tag's Dovecot
# image, with the two validation datasets of research R7 and the host name mail.example.org, and
# writes tests/fixtures/quarantine/templates/goldens.json: per template whether it parsed, and per
# dataset whether it rendered, the exception class and line of a failure, and the HTML and text.
# A template that does not finish within 10 seconds is recorded as `timeout`.
# tests/Unit/Quarantine/QuarantineTemplateRendererTest.php compares the API's check with them.
#
#   scripts/legacy/generate-template-goldens.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code); DOVECOT_IMAGE overrides
# the image (default: the one the tag's docker-compose.yml names). Runs on the host with Docker.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"
TEMPLATES="$ROOT/tests/fixtures/quarantine/templates"
HOSTNAME_VALUE=mail.example.org

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

IMAGE="${DOVECOT_IMAGE:-$(git -C "$MAILCOW_REPO" show "$TAG:docker-compose.yml" | sed -n 's/^ *image: *\(ghcr.io\/mailcow\/dovecot:[^ ]*\).*/\1/p' | head -1)}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

for template in "$TEMPLATES"/*.tpl; do
    name="$(basename "$template" .tpl)"
    container="restv2-template-golden-$$-$name"
    # --init: the interpreter is not PID 1, so the kill below ends it.
    docker run --rm -i --init --name "$container" --network none --entrypoint python3 \
        -v "$ROOT/scripts/legacy/harness/quarantine-template-golden.py:/harness.py:ro" \
        "$IMAGE" -I -B /harness.py "$HOSTNAME_VALUE" < "$template" > "$WORK/$name.json" &
    pid=$!
    for _ in $(seq 1 100); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 "$pid" 2>/dev/null; then
        docker kill "$container" >/dev/null 2>&1 || true
        wait "$pid" 2>/dev/null || true
        echo '{"timeout": true}' > "$WORK/$name.json"
    else
        wait "$pid"
    fi
    echo "$name"
done

python3 -I - "$TAG" "$IMAGE" "$WORK" "$TEMPLATES/goldens.json" <<'PY'
import json, os, sys

tag, image, work, out = sys.argv[1:5]
goldens = {'_source': {'tag': tag, 'image': image}, 'templates': {}}
for name in sorted(os.listdir(work)):
    with open(os.path.join(work, name), encoding='utf-8') as f:
        record = json.load(f)
    if 'datasets' in record:
        goldens.setdefault('datasets', record.pop('datasets'))
    goldens['templates'][name[:-5]] = record
with open(out, 'w', encoding='utf-8') as f:
    json.dump(goldens, f, ensure_ascii=False, indent=2)
    f.write('\n')
PY
