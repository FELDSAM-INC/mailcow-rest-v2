# Install the REST API and new UI

This guide installs both projects on an existing, running mailcow server. It builds the images
locally; PHP and Node.js run inside Docker.

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

## 4. Build the API image

The first build takes a few minutes. Wait until it finishes before continuing.

```bash
docker build --target production -f /opt/mailcow-rest-v2/docker/php/Dockerfile -t ghcr.io/feldsam-inc/mailcow-rest-v2:local /opt/mailcow-rest-v2
```

## 5. Build the UI image

```bash
docker build -f /opt/mailcowui-ng/docker/Dockerfile -t ghcr.io/feldsam-inc/mailcowui-ng:local /opt/mailcowui-ng
```

## 6. Open the mailcow directory

Keep this working directory for all remaining commands.

```bash
cd /root/mailcow-dockerized
```

## 7. Check that this is a first installation

This command must succeed. If an override already exists, merge the two services into it using
the [reference installation instructions](../README.md#installation).

```bash
test ! -e docker-compose.override.yml
```

## 8. Generate the API application key

The key is saved in mailcow's existing private configuration file. An existing key is preserved.

```bash
grep -q '^RESTV2_APP_KEY=' mailcow.conf || printf '\nRESTV2_APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" >> mailcow.conf
```

## 9. Select the locally built images

```bash
printf '\nRESTV2_IMAGE=ghcr.io/feldsam-inc/mailcow-rest-v2:local\nMAILCOWUI_NG_VERSION=local\n' >> mailcow.conf
```

## 10. Add the API service

```bash
cp /opt/mailcow-rest-v2/deploy/docker-compose.override.yml docker-compose.override.yml
```

## 11. Add the UI service

This appends only the UI service, under the same `services:` heading.

```bash
sed -n '/^  ng-mailcow:/,$p' /opt/mailcowui-ng/deploy/docker-compose.override.yml >> docker-compose.override.yml
```

## 12. Use local images without pulling from a registry

```bash
sed -i '/^    image:/a\    pull_policy: never' docker-compose.override.yml
```

## 13. Validate the configuration

No output means the configuration is valid.

```bash
docker compose config --quiet
```

## 14. Start the API and UI

Wait for both containers to become healthy. The API creates its own `restv2_` tables at startup.

```bash
docker compose up -d --no-deps --wait --wait-timeout 180 restv2-mailcow ng-mailcow
```

## 15. Install the nginx routes

```bash
install -m 644 /opt/mailcow-rest-v2/deploy/nginx/site.restv2.custom data/conf/nginx/site.restv2.custom
```

## 16. Check nginx configuration

Continue only when nginx reports that its configuration test is successful.

```bash
docker compose exec -T nginx-mailcow nginx -t
```

## 17. Reload nginx

```bash
docker compose exec -T nginx-mailcow nginx -s reload
```

## 18. Register the scheduled jobs

Restart Ofelia once so it discovers the API's scheduled jobs.

```bash
docker compose restart ofelia-mailcow
```

## 19. Check container health

Both services should show `healthy`.

```bash
docker compose ps restv2-mailcow ng-mailcow
```

## 20. Check the public API

```bash
curl -fsS https://mailcow-dev.feldhost.cz/api/v2/health/ready
```

## 21. Check the public UI

The response should have HTTP status `200` and a `Content-Security-Policy` header.

```bash
curl -fsSI https://mailcow-dev.feldhost.cz/ng/
```

Open **https://mailcow-dev.feldhost.cz/ng/** and sign in with your existing mailcow account.
The classic interface remains at **https://mailcow-dev.feldhost.cz/**.

## If a step fails

Read the container logs:

```bash
docker compose logs --tail 100 restv2-mailcow ng-mailcow
```

Read the nginx logs if the containers are healthy but the URLs fail:

```bash
docker compose logs --tail 100 nginx-mailcow
```
