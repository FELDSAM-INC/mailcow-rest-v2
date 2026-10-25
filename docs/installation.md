# Install the REST API and new UI

This guide installs both projects on an existing, running mailcow server. It downloads prebuilt public
images from GHCR; you do not need PHP, Node.js, a compiler or a GitHub account.
The default `latest` tags track tested builds of `main`, not a stable release.
For fixed versions, choose the tags or digests listed on the package pages.

Use **mailcow 2026-09a or newer**, including **2026-10**. Older releases remain untested:
`2026-09` shares the accepted schema, while releases through `2026-07b` currently fail
the API's schema check. See the
[supported releases and database schema check](../README.md#supported-mailcow-releases).

The examples use `mailcow-dev.feldhost.cz` and `/root/mailcow-dockerized`. Change these two values
if your server is different. You need Git, OpenSSL and Docker Compose with `--wait` support.

Run **one command block at a time**, in order, in the same Bash session. Stop if a command fails.
This is a first installation guide; an existing installation should retain its application key.

## 1. Connect to the server

```bash
ssh root@mailcow-dev.feldhost.cz
```

Enable error checking for the commands that follow:

```bash
set -euo pipefail
```

## 2. Download the API

```bash
git clone --depth 1 --branch main https://github.com/FELDSAM-INC/mailcow-rest-v2.git /opt/mailcow-rest-v2
```

## 3. Download the UI

```bash
git clone --depth 1 --branch main https://github.com/FELDSAM-INC/mailcowui-ng.git /opt/mailcowui-ng
```

## 4. Open the mailcow directory

Keep this working directory for all remaining commands.

```bash
cd /root/mailcow-dockerized
```

## 5. Check that this is a first installation

This command must succeed. If an override already exists, merge the two services into it using
the [reference installation instructions](../README.md#installation).

```bash
test ! -e docker-compose.override.yml
```

## 6. Generate the API application key

The key is saved in mailcow's existing private configuration file. An existing key is preserved.

```bash
grep -q '^RESTV2_APP_KEY=' mailcow.conf || printf '\nRESTV2_APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" >> mailcow.conf
```

## 7. Select the published images

```bash
printf '\nRESTV2_IMAGE=ghcr.io/feldsam-inc/mailcow-rest-v2:latest\nMAILCOWUI_NG_VERSION=latest\n' >> mailcow.conf
```

## 8. Add the API service

```bash
cp /opt/mailcow-rest-v2/deploy/docker-compose.override.yml docker-compose.override.yml
```

## 9. Add the UI service

This appends only the UI service, under the same `services:` heading.

```bash
sed -n '/^  ng-mailcow:/,$p' /opt/mailcowui-ng/deploy/docker-compose.override.yml >> docker-compose.override.yml
```

## 10. Validate the configuration

No output means the configuration is valid.

```bash
docker compose config --quiet
```

## 11. Download the images

No registry login or local build is required.

```bash
docker compose pull restv2-mailcow ng-mailcow
```

## 12. Start the API and UI

Wait for both containers to become healthy. The API creates its own `restv2_` tables at startup.

```bash
docker compose up -d --no-deps --wait --wait-timeout 180 restv2-mailcow ng-mailcow
```

## 13. Install the nginx routes

```bash
install -m 644 /opt/mailcow-rest-v2/deploy/nginx/site.restv2.custom data/conf/nginx/site.restv2.custom
```

## 14. Check nginx configuration

Continue only when nginx reports that its configuration test is successful.

```bash
docker compose exec -T nginx-mailcow nginx -t
```

## 15. Reload nginx

```bash
docker compose exec -T nginx-mailcow nginx -s reload
```

## 16. Register the scheduled jobs

Restart Ofelia once so it discovers the API's scheduled jobs.

```bash
docker compose restart ofelia-mailcow
```

## 17. Check container health

Both services should show `healthy`.

```bash
docker compose ps restv2-mailcow ng-mailcow
```

## 18. Check the public API

```bash
curl -fsS https://mailcow-dev.feldhost.cz/api/v2/health/ready
```

## 19. Check the public UI

The response should have HTTP status `200` and a `Content-Security-Policy` header.

```bash
curl -fsSI https://mailcow-dev.feldhost.cz/ng/
```

Open **https://mailcow-dev.feldhost.cz/ng/** and sign in with your existing mailcow account.
The classic interface remains at **https://mailcow-dev.feldhost.cz/**.

## Update an existing installation

Keep `RESTV2_APP_KEY` and your existing service configuration. From the mailcow directory:

```bash
docker compose pull restv2-mailcow ng-mailcow
```

```bash
docker compose up -d --no-deps --wait --wait-timeout 180 restv2-mailcow ng-mailcow
```

If you installed the earlier locally built images, first change their `:local` tags to `:latest`
(`RESTV2_IMAGE` and `MAILCOWUI_NG_VERSION` in `mailcow.conf`) and remove `pull_policy: never`
from these two services in `docker-compose.override.yml`. Do not regenerate the application key.

## If a step fails

If GHCR reports `denied`, check that both packages are public using the
[maintainer setup guide](ci-and-images.md#one-time-package-visibility).

Read the container logs:

```bash
docker compose logs --tail 100 restv2-mailcow ng-mailcow
```

Read the nginx logs if the containers are healthy but the URLs fail:

```bash
docker compose logs --tail 100 nginx-mailcow
```
