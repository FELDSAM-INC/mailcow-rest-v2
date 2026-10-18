#!/usr/bin/env bash
#
# Verifies deploy/nginx/site.restv2.custom inside mailcow's own nginx image (task T110, R23):
# renders sites-default.conf.j2 and nginx.conf.j2 of an upstream mailcow tag with the image's
# bootstrap.py, exactly as its entrypoint does, with the snippet mounted at
# /etc/nginx/conf.d/site.restv2.custom, and runs `nginx -t`.
#
#   scripts/dev/verify-nginx-snippet.sh [tag]        # default 2026-09a
#
# MAILCOW_REPO defaults to ./source_code (a mailcow-dockerized checkout with the tag). Runs on the
# host (Docker and git), not in the app container. Upstream hosts that nginx resolves when it loads
# its configuration are mapped to 127.0.0.1, and a throwaway self-signed certificate stands in for
# mailcow's.

set -euo pipefail

TAG="${1:-2026-09a}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: $TAG is not a tag of $MAILCOW_REPO" >&2
    exit 1
fi

IMAGE="$(git -C "$MAILCOW_REPO" show "$TAG:docker-compose.yml" | awk '/nginx-mailcow:/{found=1} found && /image:/{print $2; exit}')"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/conf.d/templates" "$WORK/ssl"
for template in nginx.conf.j2 sites-default.conf.j2; do
    git -C "$MAILCOW_REPO" show "$TAG:data/conf/nginx/templates/$template" > "$WORK/conf.d/templates/$template"
done
cp "$ROOT/deploy/nginx/site.restv2.custom" "$WORK/conf.d/site.restv2.custom"

# A throwaway certificate, generated with PHP's openssl extension in the dev image.
docker compose -f "$ROOT/compose.yaml" run --rm -T --no-deps -v "$WORK/ssl:/certs" --user "$(id -u):$(id -g)" app \
    php scripts/dev/generate-dev-certificate.php /certs cert.pem key.pem >/dev/null

echo "nginx image: $IMAGE (mailcow $TAG)"
docker run --rm \
    -v "$WORK/conf.d:/etc/nginx/conf.d" \
    -v "$WORK/ssl:/etc/ssl/mail:ro" \
    -e MAILCOW_HOSTNAME=mail.example.org -e HTTP_PORT=80 -e HTTPS_PORT=443 -e IPV4_NETWORK=172.22.1 \
    -e SKIP_SOGO=n -e SKIP_RSPAMD=n -e ENABLE_IPV6=true \
    --add-host php-fpm-mailcow:127.0.0.1 --add-host phpfpm:127.0.0.1 --add-host rspamd-mailcow:127.0.0.1 \
    --entrypoint sh "$IMAGE" -c 'python3 /bootstrap.py && grep -c "site.\*.custom" /etc/nginx/includes/sites-default.conf && nginx -t'
