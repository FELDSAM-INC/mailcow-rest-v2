# Monitoring fixtures (feature 011)

Payloads of mailcow-dockerized `2026-09a` in the exact formats its producers write, for the tests
of feature 011 and the development stand-ins (`RESTV2_FAKE_OUTBOUND=y`). They were synthesised from
the producers' code (no installation data, no real secrets): the planted `planted-secret…`
environment values must never appear in a cache, a log, a response or an audit record.

| File | Source command or producer |
|---|---|
| `containers.json` | dockerapi `GET /containers/json?all=true` (`data/Dockerfiles/dockerapi/main.py`): a map of container ID to `docker inspect`, 19 services of compose project `mailcowdockerized` (`sogo-mailcow` exited, `clamd-mailcow` present, `memcached-mailcow` health `none`) and one container of project `otherproject`. `Config.Env` holds planted secrets. |
| `host-stats.json` | dockerapi `GET /host/stats` (`DockerApi.get_host_stats()`, psutil); `memory.swap` is psutil's `sswap` tuple. |
| `stats-postfix-1.json`, `stats-postfix-3.json` | dockerapi `POST /container/{id}/stats/update`: one sample, and three samples 5 s apart (cgroup v2, `docker stats` format). |
| `stats-cgroup-v1.json` | the same with cgroup v1 counters (`Read`/`Write`/`Total` block I/O ops, `total_inactive_file`). |
| `df-ok.txt`, `df-wrapped.txt`, `df-failed.txt` | dockerapi exec `{"cmd":"system","task":"df","dir":"/var/vmail"}`: `df -H … \| tail -n1 \| tr -s [:blank:] \| tr ' ' ','`, a wrapped long device name, and the failure answer. |
| `postqueue-j.txt` | dockerapi exec `{"cmd":"mailq","task":"list"}` (`postqueue -j` as user postfix): 3 deferred messages to `partner.example`, 2 active, 2 hold, 1 incoming. |
| `make-large-queue.php` | generates `postqueue -j` output with 12,000 messages. |
| `postcat-ok.txt`, `postcat-missing.txt` | dockerapi exec `{"cmd":"mailq","task":"cat","items":[…]}` (`postcat -q` as user postfix). |
| `rspamd-history.json` | Rspamd controller `GET /history` (version 2), 30 rows newest first, with a `reject` from `spammer@example.net`. |
| `rspamd-stat.json` | Rspamd controller `GET /stat`, with undocumented members (`pools_allocated` …) that the API drops. |
| `github-latest.json`, `github-tag-2026-09a.json` | `GET https://api.github.com/repos/mailcow/mailcow-dockerized/releases/latest` and `/releases/tags/2026-09a` (legacy `update_check.php`). |
| `inc/app_info.inc.php` | written by `generate_config.sh` / `update.sh` (`MAILCOW_GIT_*`, `MAILCOW_BRANCH`). |
| `inc/vars.local.inc.php` | an operator's override `$DOCKER_TIMEOUT = 30;`. |
