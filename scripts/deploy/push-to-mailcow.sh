#!/usr/bin/env bash
#
# Builds this repository's production image from a clean `git archive main` and deploys it to a
# mailcow host without a registry:
#
#   scripts/deploy/push-to-mailcow.sh <ssh-host> <mailcow-dir>
#   scripts/deploy/push-to-mailcow.sh root@maildev.feldhost.cz /root/mailhosting
#
#   1. exports main into a temporary directory, so uncommitted files and other worktrees never
#      reach the image;
#   2. builds docker/php/Dockerfile --target production as mailcow-rest-v2:<short sha>;
#   3. streams it to the host with docker save | ssh docker load;
#   4. sets that tag as the `image:` of restv2-mailcow in <mailcow-dir>/docker-compose.override.yml,
#      checked with `docker compose config -q` before the file is replaced;
#   5. runs `docker compose up -d --no-deps restv2-mailcow`, so no other mailcow container is
#      started, recreated or restarted;
#   6. waits for the container's health check, then requests /api/v2/health/ready through
#      nginx-mailcow at https://<MAILCOW_HOSTNAME>.
#
# The service must already be in the override, with `pull_policy: never` (first installation:
# deploy/docker-compose.override.yml and the README). The previous image stays on the host for a
# rollback; its tag is printed.
#
# Environment: REF (default main), HEALTH_TIMEOUT in seconds (default 180), PUBLIC_URL (default
# https://<MAILCOW_HOSTNAME of the host's mailcow.conf>).

set -euo pipefail

SERVICE=restv2-mailcow
IMAGE_NAME=mailcow-rest-v2
DOCKERFILE=docker/php/Dockerfile
BUILD_TARGET=production
HEALTH_PATH=/api/v2/health/ready

if [[ $# -ne 2 ]]; then
    echo "usage: $0 <ssh-host> <mailcow-dir>" >&2
    exit 2
fi

HOST="$1"
MAILCOW_DIR="$2"
REF="${REF:-main}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

COMMIT="$(git -C "$ROOT" rev-parse --verify "$REF^{commit}")"
IMAGE="$IMAGE_NAME:$(git -C "$ROOT" rev-parse --short "$COMMIT")"

# Fail before the build when the host, the directory or the service is not there. The arguments
# of the remote bash are quoted here, on the client (SC2029 is intended).
# shellcheck disable=SC2029
HOSTNAME_ON_HOST="$(ssh "$HOST" "bash -s -- $(printf '%q ' "$MAILCOW_DIR" "$SERVICE")" <<'PREFLIGHT'
set -euo pipefail
cd "$1"
if ! grep -q "^  $2:\$" docker-compose.override.yml 2>/dev/null; then
    echo "error: service $2 not found in $1/docker-compose.override.yml; install it first" >&2
    exit 1
fi
sed -n 's/^MAILCOW_HOSTNAME=//p' mailcow.conf | tr -d "\"' "
PREFLIGHT
)"
PUBLIC_URL="${PUBLIC_URL:-https://$HOSTNAME_ON_HOST}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> building $IMAGE from $REF ($COMMIT)"
git -C "$ROOT" archive "$COMMIT" | tar -x -C "$WORK"
docker build -f "$WORK/$DOCKERFILE" --target "$BUILD_TARGET" \
    --label "org.opencontainers.image.revision=$COMMIT" -t "$IMAGE" "$WORK"

echo "==> loading $IMAGE on $HOST"
docker save "$IMAGE" | gzip -1 | ssh "$HOST" 'gunzip | docker load'

echo "==> deploying $IMAGE as $SERVICE in $HOST:$MAILCOW_DIR"
# shellcheck disable=SC2029
ssh "$HOST" "bash -s -- $(printf '%q ' "$MAILCOW_DIR" "$SERVICE" "$IMAGE" "$HEALTH_TIMEOUT")" <<'REMOTE'
set -euo pipefail
dir="$1" service="$2" image="$3" timeout="$4"
cd "$dir"
override=docker-compose.override.yml

# The first `image:` line inside the service's block (services are indented by two spaces).
current="$(awk -v svc="  $service:" '
    $0 == svc { inside = 1; next }
    inside && /^  [^ #]/ { exit }
    inside && /^    image:/ { print $2; exit }' "$override")"
if [[ -z "$current" ]]; then
    echo "error: no image: line in the $service block of $dir/$override" >&2
    exit 1
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
awk -v svc="  $service:" -v img="$image" '
    $0 == svc { inside = 1; print; next }
    inside && /^  [^ #]/ { inside = 0 }
    inside && !done && /^    image:/ { sub(/image:.*/, "image: " img); done = 1 }
    { print }' "$override" > "$tmp"
# Relative paths resolve against docker-compose.yml's directory, so the copy validates as is.
docker compose -f docker-compose.yml -f "$tmp" config -q
cat "$tmp" > "$override"    # keeps the file's owner and mode
docker compose config -q
echo "image: $current -> $image"

docker compose up -d --no-deps "$service"

cid="$(docker compose ps -q "$service")"
deadline=$((SECONDS + timeout))
while :; do
    status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid")"
    [[ "$status" == healthy ]] && break
    if [[ "$status" == unhealthy || "$status" == exited ]] || (( SECONDS >= deadline )); then
        echo "error: $service is $status" >&2
        docker compose logs --tail 40 "$service" >&2
        echo "roll back: set image: $current in $dir/$override, then docker compose up -d --no-deps $service" >&2
        exit 1
    fi
    sleep 3
done
echo "$service: healthy ($image)"
echo "previous image kept for a rollback: $current"
REMOTE

echo "==> checking $PUBLIC_URL$HEALTH_PATH"
for _ in 1 2 3 4 5; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$PUBLIC_URL$HEALTH_PATH" || true)"
    [[ "$code" == 200 ]] && break
    sleep 2
done
if [[ "$code" != 200 ]]; then
    echo "error: $PUBLIC_URL$HEALTH_PATH answered $code" >&2
    exit 1
fi
echo "deployed $IMAGE to $HOST:$MAILCOW_DIR; $HEALTH_PATH answers 200"
