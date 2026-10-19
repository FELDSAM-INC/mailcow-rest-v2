# Research: System Settings

**Feature**: `specs/013-system-settings` | **Date**: 2026-10-18 | **Plan**: [plan.md](./plan.md)

This document records only the decisions specific to feature 013. Every shared technical decision
comes from feature 001's design and is reused unchanged: Laravel 13 on PHP 8.4, the Docker dev
stack, the `2026-09a` schema fixture, the single `mysql` connection with `restv2_` tables, the
`restv2:` and `mailcow` Redis connections, problem URIs, contract validation in tests, the request
pipeline, the audit logger, `ETag`, `ListQuery`, `ApiFormRequest` and the configuration reader
`MailcowConfig` (001-R1 to 001-R29). References `001-Rn`, `002-Dn` and `005-Rn` point to those
features' research.

Decisions follow the owner's precedence: legacy parity (constitution IV), then the constitution and
`docs/conventions.md`, then best practice. Legacy references are paths in mailcow-dockerized
`2026-09a` (`git -C ~/Projects/mailhosting show 2026-09a:<path>`). The FeldHost fork
(`feldhost-202609a`) differs for this feature only in the four ways listed in the spec (default
title, default logos, the hidden license card, comments of the default global Sieve scripts).

---

## 0. Reused components of earlier features

Features are implemented in number order, so 013 builds on code that exists when it starts.

| Need | Owner | Component | Used for |
|---|---|---|---|
| Pipeline, route modes, CSRF, key scope | 001 (R8) | `RequireAuth`, `VerifyCsrfToken`, `EnforceKeyScope`, `EnforcePendingActions` | Every route |
| Read-only POST marker | 005 (R5) | `->defaults('restv2.readOnly', true)`, honoured by `EnforceKeyScope` | Mass mail recipient preview (R19) |
| Capabilities path convention | conventions | `/capabilities/{area}`, `get<Area>Capabilities` | `GET /capabilities/settings` (R23) |
| Configuration reader | 001 (R14) | `App\Services\Config\MailcowConfig`, `resources/bin/export-mailcow-vars.php` | `$LOGO_LIMITS`, `$RSPAMD_MAPS`, `$MAILCOW_APPS` (R2) |
| Public configuration and app links | 001 (R15) | `App\Services\Branding\PublicConfigService`, `ProductName` | Brand colours added (R5), defaults of the UI texts |
| Problems, ETags, lists, form requests | 001 | `ProblemType`, `ProblemException`, `ETag`, `ListQuery`, `ApiFormRequest` | Everywhere |
| Audit logger and target filters | 001 (R22), 002 (FR-058) | `App\Services\Audit\AuditLogger`, `targetType`/`targetId` filters of `listAuditEvents` | FR-086 to FR-088 (R24) |
| Refusal auditing | 008 (R16; or the first feature that built it) | `App\Services\Audit\RefusalAuditor`, route default `restv2.audit`, per-target resolvers | Refused attempts (R24) |
| Container service client | 002 (D14) | `App\Clients\DockerApi\DockerApiClient` (`restart`, `exec`, `DockerCallResult`), `tests/Support/Fakes/FakeDockerApiClient` | Dovecot restart (R14), Rspamd password (R16) |
| Domain name and scope lookup | 002 | `App\Support\Domains\DomainName`, the domain-in-scope lookup | Quota copy recipients (R10) |
| Password policy read and tag | 003 (FR-063) | `App\Services\PasswordPolicy\PasswordPolicyService`, `GET /password-policy` | Policy change (R8) |
| Legacy Sieve parser | 005 (R13) | `resources/legacy/sieve/`, `App\Support\Sieve\SieveValidator` | Global Sieve filters (R14) |
| Temporary-failure, outcome-unknown, container-action-failed | 002, 003, 011 | problem types and `ContainerProblem` | R1, R16 |

If an implementation detail of one of these components differs from what this document assumes,
the component wins and the 013 task adapts to it; the behaviour required by the spec does not
change.

---

## 1. Storage, consistency and configuration

### R1. Settings in mailcow's Redis: atomic writes, version tags and audit

- **Decision**:
  - Settings that legacy keeps in Redis are read and written on the `mailcow` connection (database
    0, no prefix) with legacy's keys and formats (data-model.md). Nothing is cached (conventions:
    freshness).
  - `App\Services\Settings\RedisSettingsStore` performs every write as an optimistic transaction:
    `WATCH` the resource's keys, read the current raw values, compute the version tag, compare it
    with `If-Match` (412 on mismatch), apply the merge patch to the current values, validate the
    result as a whole, then `MULTI` … `EXEC`. When `EXEC` aborts because a watched key changed
    (legacy wrote meanwhile), the transaction is retried up to three times from the read; the
    `If-Match` comparison is repeated each time, so an outdated tag still answers 412.
  - A multi-key resource (UI texts write up to nine keys) is written in one `MULTI` block, so
    Redis never shows half a change (FR-091).
  - **Audit atomicity**: the success audit record is inserted inside an open database transaction
    before `EXEC`; the database transaction commits after `EXEC` succeeds. If `EXEC` fails, the
    database transaction rolls back. If the commit fails after `EXEC`, the service restores the old
    raw values with a compensating `MULTI` block and answers 500 (spec edge case "Audit record
    cannot be written"). Refusals are audited in their own transaction (001-R22).
  - **Version tags** are strong ETags (`ETag::of()`, 001) over the raw stored values of the
    resource's keys, `null` for a missing key, so that a change through the legacy UI or
    `/api/v1` changes the tag (FR-008). Exception: the password policy uses 003's tag over the
    effective values (R8). Legacy's own `customize('get', 'ui_texts')` moves `UI_IMPRESS` to
    `UI_FOOTER` when an administrator opens the legacy screen; the tag then changes, which is
    correct because the stored values changed.
  - **Redis unavailable**: any `RedisException` or connection error on the `mailcow` connection
    becomes `503 temporary-failure` with `Retry-After: 5` (002's type), with no partial change.
    Tests point the `mailcow` connection at a closed port (002-D26).
- **Rationale**: Constitution II demands `If-Match`; WATCH/MULTI makes the comparison and the write
  atomic against other API requests and detects concurrent legacy writes, which never use
  version tags. Writing the audit row first keeps "no change without its audit record".
- **Alternatives considered**: A Redis lock key was rejected: legacy does not honour it, and WATCH
  gives the same protection without a lock. A Lua script was rejected: harder to read and test, and
  WATCH already covers the read-compare-write.

### R2. Legacy and server configuration

- **Decision**: 001's `MailcowConfig` is extended (conventions: "features reuse this reader") with
  `logoLimits()` (`$LOGO_LIMITS`: `max_size`, `max_width`, `max_height`; defaults 15 MiB, 1920,
  1920) and `rspamdRegexMaps()` (`$RSPAMD_MAPS['regex']`, name → file, the 13 upstream entries as
  default); `export-mailcow-vars.php` prints both. `$MAILCOW_APPS` is already exported (001-R15).
  New and reused settings:

  | Variable | Default | Meaning |
  |---|---|---|
  | `MAILCOW_HOSTNAME` | (001) | Default UI name, CORS server origin, mass mail sender suggestion, LMTP `LHLO`, `Message-ID` domain |
  | `DEMO_MODE` | `n` | `mailcow.conf` name; `y` refuses customisation writes (FR-012), as `prerequisites.inc.php` does |
  | `COMPOSE_PROJECT_NAME`, `RESTV2_DOCKERAPI_URL`, `RESTV2_DOCKER_TIMEOUT` | (002-D24) | Container service |
  | `RESTV2_LOGO_LIMITS` | from `vars.inc.php` | JSON override of `$LOGO_LIMITS` (001-R14 precedence) |
  | `RESTV2_RSPAMD_MAPS` | from `vars.inc.php` | JSON override of `$RSPAMD_MAPS` |
  | `RESTV2_RSPAMD_MAP_DIR` | `/mailcow/rspamd_custom_maps` | Mount of `data/conf/rspamd/custom` (R13) |
  | `RESTV2_GLOBAL_SIEVE_DIR` | `/mailcow/global_sieve` | Directory holding the `before` and `after` file mounts (R14) |
  | `RESTV2_TEMPLATE_DIR` | `/mailcow/templates` | Mount of `data/assets/templates` (R9) |
  | `RESTV2_MAILCOW_LANG_DIR` | `/mailcow/web/lang` | Mount of `data/web/lang` (R22) |
  | `RESTV2_RSPAMD_SETTINGS_MAP_URL` | `http://nginx:8081/settings.php` | Effective settings map (R12) |
  | `RESTV2_RSPAMD_UI_URL` | `/rspamd/` | Address returned by the capabilities (FR-094) |
  | `RESTV2_LMTP_HOST`, `RESTV2_LMTP_PORT` | `dovecot-mailcow`, `24` | Mass mail delivery, as legacy `sys_mail()` (R18) |
  | `RESTV2_MASS_MAIL_RATE` | `10` | Messages per second, 1 to 100 (FR-076) |
  | `RESTV2_MASS_MAIL_DELIVERY_DEADLINE` | `5` | Seconds a single LMTP transaction may take (SC-008) |
  | `RESTV2_LICENSE_VERIFICATION` | `true` | FR-085; the FeldHost deployment sets `false` |
  | `RESTV2_LICENSE_VERIFY_URL` | `https://verify.mailcow.email` | License service (R20) |

  `RESTV2_AUDIT_RETENTION_DAYS` (001) also bounds mass mail job retention (FR-080).
- **Rationale**: One configuration reader, `mailcow.conf` names where they exist, and FeldHost
  behaviour as a server setting with the upstream default (conventions).

---

## 2. Branding

### R3. Logo upload: format, detection, limits and storage

- **Decision**:
  - **Request**: `PUT /settings/logos/{variant}` with the raw file as the body (any
    `Content-Type`; the contract lists the four image types and `application/octet-stream`). A raw
    body avoids multipart parsing and lets the API size-check before decoding.
  - **Body limits**: 001's nginx snippet allows 1 MiB. This feature adds a nested
    `location ^~ /api/v2/settings/logos/` with `client_max_body_size 16m` (15 MiB plus slack) and
    raises the general limit to `5m`, because templates, scripts, maps and mass mail bodies may
    each be 1 MiB (two of them per request, plus JSON escaping). `docker/php/php.ini` gets
    `post_max_size = 20M`. An operator who raises `$LOGO_LIMITS['max_size']` must raise the nginx
    limit too (quickstart).
  - **Detection** (`App\Support\Images\ImageInspector`), from the bytes only: PNG
    (`89 50 4E 47 0D 0A 1A 0A`), JPEG (`FF D8 FF`), GIF (`GIF87a` / `GIF89a`), otherwise SVG when
    the content parses as XML whose root element is `svg` in the SVG namespace (R4). Anything else
    is `unsupportedImageType` (params `allowed`). The declared `Content-Type` is ignored (spec edge
    case "declared type differs from content").
  - **Checks, in order**: empty body → `required`; size above `max_size` → `tooLong`
    (`max`, `unit: bytes`); for raster images, `getimagesizefromstring()` must give dimensions,
    which must not exceed `max_width` × `max_height` (`imageDimensionsExceeded`) — checked before
    decoding, so a decompression bomb is never decoded; then GD's `imagecreatefromstring()` must
    decode the image (`invalidImage`), as legacy's `Imagick::valid()` check. Animated GIFs pass (GD
    decodes the first frame). SVGs skip the dimension limit, as in legacy.
  - **Storage**: `MAIN_LOGO` / `MAIN_LOGO_DARK` = `data:<detected type>;base64,<data>`, the legacy
    format, with the detected type (`image/png`, `image/jpeg`, `image/gif`, `image/svg+xml`).
  - **Read** (`LogoSetting`): the data URI is decoded; size is the decoded byte length; raster
    dimensions from `getimagesizefromstring()`; SVG dimensions from the root `width` and `height`
    attributes when they are numbers without a unit or in `px` (rounded), or in `pt`, `pc`, `mm`,
    `cm`, `in` (converted at 96 dpi); `%`, `em`, `ex` or a missing attribute give `null`. A
    stored logo that would be refused today (legacy uploaded it) is read the same way. Legacy's
    declared types `image/x-png` and `image/pjpeg` read as `image/png` and `image/jpeg` (as 001
    serves them); any other media type in the data URI reads as `mediaType: null`, `isSet: true`.
  - **Delete**: `DEL` of the variant's key only (legacy deletes both in one form). Deleting an unset
    logo answers 204 without audit (conventions: idempotent no-ops are not audited, 001-R22).
  - **Audit**: old and new `mediaType`, `sizeBytes`, `width`, `height`; never the image.
- **Rationale**: FR-013 to FR-018. GD is bundled with PHP (`docker-php-ext-install gd`) and has a
  far smaller attack surface than ImageMagick, whose SVG and MSL delegates have a long CVE record;
  only decode validity is needed, not conversion.
- **Alternatives considered**: `multipart/form-data` was rejected: it adds a parsing layer and a
  field name for one file. JSON with base64 was rejected: 33 % larger and the whole file must sit in
  a JSON string. Imagick (legacy) was rejected for the reason above; `finfo` was rejected for SVG,
  which it does not recognise reliably.

### R4. SVG validation: reject, never strip

- **Decision**: `App\Support\Images\SvgSafetyInspector` parses the SVG with PHP's `XMLReader` and
  `DOMDocument` (bundled `libxml`), with `LIBXML_NONET`, without `LIBXML_NOENT` and without
  `LIBXML_DTDLOAD`, and refuses (`unsafeSvgContent`, params `construct` and `line`) when:
  1. the document is not well-formed (`notWellFormed`) or its root is not `svg` in the SVG namespace
     (`rootNotSvg`);
  2. a `DOCTYPE` node exists at all, which covers internal entity declarations and external DTDs
     (`doctype`); processing instructions other than the XML declaration and `xml-stylesheet`
     instructions are refused too (`styleImport`);
  3. any element is named `script` in any namespace (`scriptElement`);
  4. any attribute name starts with `on` in any namespace (`eventHandlerAttribute`);
  5. any element is `foreignObject`, or any element is in the XHTML namespace
     (`http://www.w3.org/1999/xhtml`) or has no namespace (`foreignContent`); elements of other
     namespaces (Inkscape, Sodipodi, RDF, Dublin Core, Creative Commons metadata) are inert in an
     image and accepted, as long as their content passes the same rules;
  6. any `href`, `xlink:href` or `src` attribute, any `url(…)` in a `style` attribute, a `style`
     element or a presentation attribute (`fill`, `stroke`, `filter`, `clip-path`, `mask`,
     `marker-*`, `cursor`), is not either an in-document reference (`#id`) or a
     `data:image/(png|jpeg|gif|webp);base64,` URL; a `javascript:`, `vbscript:` or `data:text/html`
     scheme is `scriptUrl`, any other external target `externalReference` (a nested
     `data:image/svg+xml` is refused as `externalReference`);
  7. a `style` element or attribute contains `@import` (`styleImport`) or `expression(`
     (`scriptUrl`);
  8. an `animate`, `set` or `animateTransform` element targets an attribute named `href`,
     `xlink:href` or starting with `on` through `attributeName` (`scriptUrl`).

  The first violation in document order is reported, with its line number. The stored file is
  exactly the uploaded bytes.
- **Rationale**: FR-016 and the clarification (refuse, don't strip). An allowlist of dangerous
  constructs, rather than an allowlist of every SVG element, keeps legitimate logos from design
  tools (which carry editor metadata) accepted while covering every vector that matters when an SVG
  is opened directly (the legacy UI embeds it as an image; 001 serves it with a sandboxing CSP, so
  this is defence in depth). The hostile test set of SC-003 is a fixture directory.
- **Alternatives considered**: `enshrined/svg-sanitize` (GPL-2.0-or-later) was rejected: it is
  built to strip, and its issue report is not designed as a stable refusal contract. Accepting
  SVGs unchecked (legacy) was rejected by the spec.

### R5. Brand colours and their contrast

- **Decision**:
  - Stored by this API in the Redis hash `restv2:brand-colors` (fields `light`, `dark`, lower-case
    `#rrggbb`; a cleared colour is `HDEL`) on the `restv2` connection. Redis is mailcow's
    persistent settings store, the public configuration already reads Redis on every request, and
    no table is needed for two values.
  - `App\Support\Color\WcagContrast` computes the WCAG 2.2 relative luminance
    (`c ≤ 0.04045 ? c/12.92 : ((c+0.055)/1.055)^2.4`, weights 0.2126, 0.7152, 0.0722) and the ratio
    `(L1 + 0.05) / (L2 + 0.05)`. A colour is accepted when the exact ratio is ≥ 4.5 against its
    reference (`#ffffff` light, `#1d2939` dark); the reported `ratio` parameter is rounded **down**
    to two decimals, so a refused colour never displays as 4.50.
  - Effective colours follow FR-031; the foreground is `#ffffff` or `#000000`, whichever has the
    higher ratio on the colour (ties: `#000000`). Worked examples (unit-tested): `#1d4ed8` → 6.70:1
    on white, 2.19:1 on `#1d2939`, foreground white; `#ffd700` → 1.40:1 on white (refused).
  - `PublicConfig.brandColors` (001's schema, extended additively) carries the effective colours;
    001's `PublicConfigService` reads the hash and its `ETag` covers it. Clients that see no
    `brandColors` (a server without 013) treat both themes as `null`.
- **Rationale**: FR-029 to FR-034 and the clarification. Exact comparison avoids false
  acceptances at the boundary; rounding down avoids a confusing error message.
- **Alternatives considered**: An API-owned table was rejected for two values. Storing in a
  legacy-looking key without prefix was rejected: API-owned keys carry `restv2:` (001-R6).

### R6. UI texts, login page options, IP check and demo mode

- **Decision**:
  - **Escaped fields**: `pageTitle`, `productName`, `appsHeading` are written as
    `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true)` (PHP 8's
    default flags, which legacy uses) and read with `htmlspecialchars_decode($v, ENT_QUOTES)`
    (001-R15). A plain text `Acme & Co Mail` is stored `Acme &amp; Co Mail`, which legacy renders
    raw as "Acme & Co Mail" (AS-1.5); `<b>` is stored `&lt;b&gt;` and shown literally.
  - **Validation**: single-line fields at most 255 characters without control characters
    (`invalidFormat`, `format: singleLine`); HTML fields and the announcement text at most 65,535
    characters; announcement type `info`, `warning`, `danger` or `null` (stored as an empty string,
    as legacy's `false`); active stored `1`/`0`.
  - **Merge**: unsent members keep their stored raw values (legacy overwrites all). When the stored
    `UI_FOOTER` is empty and `UI_IMPRESS` is not, the read returns `UI_IMPRESS` as `footerHtml`,
    and the next PATCH of the UI texts writes `UI_FOOTER` (the sent value, or the migrated value
    when the footer was not sent) and deletes `UI_IMPRESS` in the same `MULTI` block (FR-023).
  - **Defaults** in the read: `pageTitleDefault` and `productNameDefault` come from 001's
    `ProductName` (`RESTV2_DEFAULT_UI_NAME` or `<MAILCOW_HOSTNAME> - mail UI`).
  - **Login page options**: `CUSTOM_LOGIN` JSON with the four keys in legacy's order
    (`hide_user_quicklink`, `hide_domainadmin_quicklink`, `hide_admin_quicklink`, `force_sso`) and
    integer values, encoded with `json_encode()` without flags, byte-identical to legacy.
  - **IP check**: `IP_CHECK` `1`/`0`; a missing key reads as off.
  - **Demo mode**: when `DEMO_MODE=y`, every write of logos, UI texts, login page options, app
    links, brand colours and the IP check answers `403 demo-mode`, after authorisation and before
    validation (legacy checks demo mode first, but an unauthorised caller must still get
    `forbidden`). It is audited as a refusal with reason `demoMode`.
- **Rationale**: FR-019 to FR-024, FR-012, FR-035; storage parity (constitution IV).

### R7. App links

- **Decision**: `APP_LINKS` is written as `json_encode()` (no flags, so slashes are escaped exactly
  as legacy writes them) of a list of one-key maps `{name: {link, user_link, hide}}`, with
  `user_link` = `""` when `signedInUrl` is `null` (legacy's form sends an empty field) and `hide` a
  boolean. Reading converts old-style entries `{name: "address"}` as legacy and 001 do. An address
  is valid when it is an absolute URL whose scheme is `http` or `https` with a host (parsed with
  `parse_url`, no whitespace or control characters, at most 2,048 characters), or a path starting
  with exactly one `/` (`//host` is refused as scheme-relative). `%u` is allowed anywhere in
  `signedInUrl`. At most 50 entries. The built-in links come from `MailcowConfig::mailcowApps()`
  and are returned with 001's `AppLink` mapping, read-only.
- **Rationale**: FR-025 to FR-028 (the orchestrator decision removes a separate effective read;
  001's public configuration already exposes the links).

---

## 3. Password policy and notifications

### R8. Password policy change

- **Decision**: `PATCH /password-policy` is an operation of 003's path item (the
  `password-policy` module), so the read and the change share one resource and one tag. 003's
  `PasswordPolicyService` gains `update(array $patch, ?string $ifMatch, Principal $actor)`: it
  reads the effective policy (defaults 6/0/0/0/0 for missing fields, 003's rule), compares 003's tag
  (`SHA-256` of `length=<n>;chars=<0|1>;special_chars=<0|1>;lowerupper=<0|1>;numbers=<0|1>`,
  base64url, quoted), merges, validates `minLength` 3–64 (`outOfRange`), and writes all five fields
  with `HMSET PASSWD_POLICY` in the R1 transaction (legacy writes all five too). The response is
  003's `PasswordPolicyResponse`. A test asserts that `GET /password-policy` returns exactly the
  `ETag` of the PATCH response.
- **Rationale**: FR-036 to FR-038, 003 FR-063 and the conventions' shared-tag rule. No reload is
  needed: legacy and 003 read the hash per password check.

### R9. Password reset and quota notifications

- **Decision**:
  - Reset notification keys `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_TEXT`, `PW_RESET_HTML`.
    When both templates are empty, the read returns `pw_reset_text.tpl` and `pw_reset_html.tpl`
    from `RESTV2_TEMPLATE_DIR` with `templatesAreDefault: true` (legacy reads `/tpls/…`). The
    sender must be empty or pass `FILTER_VALIDATE_EMAIL` and contain no byte in `\x00-\x1F\x7F`
    (legacy strips `\x00-\x1F\x80-\xFF` silently and then empties an invalid address; the API
    refuses instead); the subject at most 255 characters without control characters; each template
    at most 1,048,576 bytes. `passwordResetAvailable = sender ≠ "" && subject ≠ ""`.
  - Quota notification keys `QW_SENDER`, `QW_SUBJ`, `QW_HTML`. The read returns the effective
    values: `QW_SENDER` or `quota-warning@localhost`, `QW_SUBJ` or `Quota warning`, `QW_HTML` or
    `quota.tpl` from `RESTV2_TEMPLATE_DIR` (the file Dovecot reads as `/templates/quota.tpl`), with
    the three `…IsDefault` markers, exactly as `quota_notify.py` decides. An empty value written
    through the API deletes nothing: it stores `""`, which Dovecot treats as unset (`r.get(...) or
    default`). Legacy's unused `retention_size` and `release_format` inputs are not offered.
  - A missing template file reads as an empty template and logs a warning once; it does not fail
    the read.
- **Rationale**: FR-039 to FR-045; the templates are mounted read-only so that the defaults are the
  deployed ones (also the FeldHost ones).

### R10. Quota notification copy recipients

- **Decision**:
  - `QW_BCC` hash, field = domain (lower-case ASCII, 002's `DomainName` normalisation), value =
    `json_encode(['bcc_rcpts' => array_values($list), 'active' => 0|1])`, always a JSON **list**
    and an **integer** `active`. Legacy writes an object when it dropped entries (`array_unique`
    keeps keys), which `quota_notify.py` would iterate by key; the spec's parity table records the
    list as a deviation. Reading accepts both shapes (values of an object).
  - Entries are trimmed and validated with `FILTER_VALIDATE_EMAIL` plus the control-character
    check; duplicates are removed case-insensitively keeping the first spelling; an empty list
    forces `active = 0`.
  - **Scope**: the domain must be a row of `domain` (not `alias_domain`) and in the caller's scope
    (001's `Scope`: all domains for administrators, active `domain_admins` assignments for domain
    administrators); otherwise 404 (conventions). Legacy's `hasDomainAccess` also accepts alias
    domains; the spec refuses them (FR-048).
  - The tag covers the raw `HGET QW_BCC <domain>` value.
- **Rationale**: FR-046 to FR-049; Dovecot reads the same hash for every notification.

---

## 4. Rspamd

### R11. Settings rules

- **Decision**:
  - Table `settingsmap` (`id`, `desc`, `content`, `created`, `modified`, `active`), model
    `App\Models\Mailcow\RspamdSettingsRule`, written only by `App\Services\Rspamd\RspamdSettingsRuleService`.
    Creation inserts `content` trimmed (legacy trims only on edit; trimming at creation changes
    nothing Rspamd sees and keeps the stored form uniform), `desc`, `active` (default `0`);
    `created` and `modified` are left to the database.
  - **Structure check** (`App\Support\Rspamd\UclStructureChecker`): a single pass over the content
    that tracks a stack of `{` and `[`, skips `#` comments to the end of the line, C-style
    `/* … */` comments, and single- or double-quoted strings with backslash escapes, and also skips
    UCL heredocs (`<<EOD` … `EOD`). It reports the first `unexpectedBrace`, `unexpectedBracket`
    (closer without matching opener or wrong nesting), `unclosedString`, or at the end
    `unclosedBrace` / `unclosedBracket`, with the line of the offending or opening character
    (`unbalancedStructure`, params `line`, `construct`). Regular expressions inside quoted strings
    (`"/^((?!.*@domain\.tld).)*$/"`) are therefore ignored. All three upstream rspamd presets pass
    (fixture test).
  - **List**: `ListQuery` with sort `id` (default asc), `description` (`desc`), `active`,
    `createdAt`, `modifiedAt`; filter `active`; `q` over `desc` and `content`.
  - **Tag**: `id|desc|sha256(content)|active`.
  - **Delivery to Rspamd**: unchanged. Rspamd polls `settings.php` on nginx-mailcow's internal
    port, which emits active rows; the PHP endpoint answers `304` until a table's `UPDATE_TIME`
    changes. A write through the API is a normal row write, exactly like legacy's.
- **Rationale**: FR-050 to FR-055 and the clarification on structural checks.
- **Alternatives considered**: Parsing UCL fully (`rspamadm configtest` through dockerapi) was
  rejected: dockerapi offers no such command and adding one would patch another container
  (constitution V).

### R12. Effective settings map

- **Decision**: `App\Clients\Rspamd\SettingsMapClient` (interface) with the adapter
  `HttpSettingsMapClient`: `GET RESTV2_RSPAMD_SETTINGS_MAP_URL` (Laravel HTTP client, connect
  timeout 5 s, total 10 s, no `If-Modified-Since`, so the PHP endpoint always answers 200), as
  legacy's `file_get_contents('http://nginx:8081/settings.php')`. A connection error, a timeout or a
  non-200 status answers `503 temporary-failure`. The content is returned unchanged.
- **Rationale**: FR-060; the API container is on `mailcow-network`, where nginx-mailcow has the
  alias `nginx`.

### R13. Global regex maps: shared files and coexistence

- **Decision**:
  - **Mount**: the override mounts `./data/conf/rspamd/custom` read-write at
    `RESTV2_RSPAMD_MAP_DIR`, as php-fpm-mailcow mounts it at `/rspamd_custom_maps`. The API
    container runs as uid 82 (001-R23), the owner rspamd-mailcow's entrypoint gives every file of
    the directory (`chown -R 82:82 /etc/rspamd/custom/*`, mode 0644). The directory itself belongs
    to `_rspamd` with mode 0755, so neither php-fpm nor the API can create files in it.
  - **Editable maps**: `MailcowConfig::rspamdRegexMaps()`; the identifier is the file name, and any
    other name answers 404 (FR-059). A configured map whose file is missing reads as
    `available: false` and a replacement answers `409 regex-map-unavailable` (FR-058).
  - **Write**: because no new file can be created in the directory, an atomic rename is impossible.
    `App\Support\Files\SharedFileWriter::replaceInPlace()` opens the existing file with `c+`, takes
    `flock(LOCK_EX)` (serialising writers of this API; legacy does not lock), `ftruncate(0)`,
    writes the whole content in a single `fwrite`, `fflush`, `fsync`, releases the lock. The content
    is `trim($content) . "\n"` (legacy: `PHP_EOL`). Then, like legacy (`sleep(1.5); touch()`), it
    waits until the wall clock is at least one second past the write's mtime (at most 1.5 s, through
    001's injectable `Sleeper`) and `touch()`es the file. Rspamd checks file maps by `stat()`
    modification time in whole seconds: if it read the file while it was being written, the later
    mtime makes it read the complete content at its next check. The spec's FR-058 was aligned with
    this (clarification of 2026-10-18).
  - **Version tag**: `sha256` of the file content (base64url, quoted). `If-Match` is compared under
    the file lock. The legacy UI never sends tags and can overwrite (spec edge case).
  - **Line validation** (`App\Support\Rspamd\RegexMapValidator`), per line after removing a
    trailing `\r`, matching legacy's browser check: an empty line (zero characters) or a line
    starting with `#` passes; otherwise the line must start with `/`
    (`notSlashDelimited`), end with `/` followed by at most one of `i`, `m`, `s`
    (`tooManyFlags` for two or more letters after the closing slash, `notSlashDelimited`
    otherwise), its pattern (between the first and the last `/`) must not contain `/`
    (`innerSlash`; legacy's `split('/')` refuses such lines), and
    `preg_match("\x01{$pattern}\x01{$flag}", '')` must not fail (`doesNotCompile`; PCRE is the
    library Rspamd uses for these maps, closer than legacy's JavaScript `RegExp`). Whitespace-only
    lines fail, as in legacy. The first failing line is reported (`invalidRegexLine`, params `line`,
    `reason`). Every map file shipped in `2026-09a` passes (fixture test).
  - **Audit**: old and new size in bytes; never the content.
- **Rationale**: FR-056 to FR-059, constitution V (no change to rspamd-mailcow) and VI (no root
  process).
- **Alternatives considered**: Writing a temporary file in the directory and renaming was rejected
  (permission denied). Copying to another directory and renaming was rejected (`rename()` across
  mounts fails with `EXDEV`). Asking dockerapi to write was rejected (no such command; patching it
  violates constitution V). Running the API as `_rspamd` was rejected: files it created would not be
  writable by php-fpm (uid 82) until Rspamd restarts.

### R14. Global Sieve filters: files, validation and the Dovecot restart

- **Decision**:
  - **Mount**: the override mounts `./data/conf/dovecot/global_sieve_before` and
    `./data/conf/dovecot/global_sieve_after` read-write at `RESTV2_GLOBAL_SIEVE_DIR/before` and
    `/after`, as php-fpm-mailcow mounts them at `/global_sieve/before` and `/after`. php-fpm's
    entrypoint gives them to uid 82. They are single-file bind mounts, so they can only be
    rewritten in place (a rename onto a bind-mounted file fails with `EBUSY`); Dovecot reads them
    only when it starts (its entrypoint copies them to `/var/vmail/sieve/` and runs `sievec`), so
    there is no partial-read window.
  - **Validation**: CR LF and lone CR become LF (legacy); at most 1,048,576 bytes; an empty or
    comment-only script is accepted without parsing (the legacy server accepts it; the legacy
    form's "required" is browser-only); otherwise 005's `SieveValidator` (the unmodified legacy
    parser) must accept it; on failure `sieveSyntax` with `message` (the parser's message, which
    starts with `line N:`) and `line` (`SieveException::getLineNo()`).
  - **Write**: `SharedFileWriter::replaceInPlace()` without the touch step. A missing file answers
    `409 global-sieve-filter-unavailable` with no restart (legacy restarts anyway).
  - **Restart**: unless `deferRestart`, `DockerApiClient::restart('dovecot-mailcow')` (002-D14),
    bounded by `DOCKER_TIMEOUT`. `success` → `done`; `failed` → `failed` with the service's
    message; `unknown` (timeout) → `failed` with the detail "no answer within DOCKER_TIMEOUT; the
    restart may still happen". The write is never undone because of the restart.
  - **Order and audit**: audit records are immutable and the record must carry the restart outcome,
    so the service runs: open the database transaction → write the file → restart (or defer) →
    insert the audit record with the outcome → commit. If the commit fails, the old content is
    written back and the answer is 500; when a restart already happened, Dovecot runs the new script
    until its next start, which is logged as an error.
  - **Tag**: `sha256` of the file content.
- **Rationale**: FR-062 to FR-065 and the clarification on deferred restarts.
- **Alternatives considered**: `sievec` through dockerapi was rejected: dockerapi has no such
  command (its Sieve tasks only list and print user scripts), and adding one would patch another
  container. The legacy parser is what legacy validates with, so acceptance is identical.

### R15. Container service calls

- **Decision**: 002's `DockerApiClient` is reused for `restart('dovecot-mailcow')` (R14) and
  `exec('rspamd-mailcow', ['cmd' => 'rspamd', 'task' => 'worker_password', 'raw' => $password])`
  (R16). `DockerCallResult::failed` carries the service's `msg`; `unknown` means timeout. The
  adapter must never log request bodies (the Rspamd password travels in one); a unit test asserts
  that a failing `exec` with a password leaves no trace of it in the log channel.
- **Rationale**: One client for all features (002-D14), legacy's exact calls.

### R16. Rspamd web interface password

- **Decision**:
  - Route mode `restv2.auth:session` (001-R8): keys get `403 session-required` (FR-006), whatever
    their scope. Validation: 6 to 256 characters (`mb_strlen`), no `\x00-\x1F\x7F`
    (`invalidFormat`, `format: singleLine`).
  - The service calls `exec('rspamd-mailcow', {cmd: rspamd, task: worker_password, raw})`, legacy's
    exact call; dockerapi runs `rspamadm pw`, writes `override.d/worker-controller-password.inc`
    and restarts Rspamd. `success` → 204; `failed` → `502 container-action-failed`
    (`container: rspamd-mailcow`, no `serviceMessage`, detail "the password may have been stored and
    would apply at Rspamd's next start"); `unknown` → `504 outcome-unknown` (`step:
    rspamdUiPassword`).
  - **Secrecy**: the request body is never logged (001's `RedactSecrets` drops keys matching
    `pass`); the Form Request marks `password` as `dontFlash`; the audit record has
    `details: {passwordChanged: true}` only; exceptions never include the body. SC-012's scan is a
    test over the log channel, the audit table and all responses of the suite.
- **Rationale**: FR-066, FR-067; conventions (secrets, keys).

---

## 5. Mass mail

### R17. Background jobs: storage, runner, rate, cancellation and recovery

- **Decision**:
  - **Storage**: API-owned tables `restv2_mass_mail_jobs` and `restv2_mass_mail_recipients`
    (data-model.md). All recipients are inserted when the job is created (one batch insert of up to
    tens of thousands of rows), which freezes the selection (FR-074, "mailbox deleted between
    preview and delivery").
  - **No Laravel queue**: a job is a row, not a queued message. The runner is the artisan command
    `restv2:mass-mail-run --max-time=290`, started by mailcow's ofelia scheduler inside the API
    container every 10 seconds (`ofelia.job-exec.restv2_mass_mail.schedule: "@every 10s"`,
    `no-overlap: "true"`), the mechanism 001 uses for the audit purge (001-R22). A Redis lock
    (`Cache::lock('mass-mail-runner', 330)` on the `restv2` store) guarantees one runner even if two
    schedulers fired. Without a running job the command exits at once.
  - **Single running job**: `restv2_mass_mail_jobs.running_slot` is `1` while the job runs and
    `NULL` otherwise, under a unique index, so the database itself refuses a second running job
    (`409 mass-mail-already-running`, FR-075); the service checks first to report `runningJobId`.
  - **Claim and deliver**: for the running job, the runner picks the next recipient
    (`outcome = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= now)`, by address) and
    claims it in a transaction that locks the job row (`SELECT … FOR UPDATE`, status must be
    `running`) and sets the recipient to `delivering` (`attempts + 1`, `last_attempt_at`). It then
    delivers over LMTP (R18) and records `delivered`, `failed` (permanent reply, with Dovecot's
    reply text) or, for a temporary reply or connection failure, `pending` with
    `next_attempt_at = now + 30 s, 60 s, 120 s` for attempts 1 to 3; a fourth temporary failure is
    `failed` (FR-076: 3 retries within 5 minutes). When the job is no longer running at record time
    (cancelled during the delivery), a temporary failure becomes `skipped`.
  - **Rate**: before each claim the runner sleeps until `1 / RESTV2_MASS_MAIL_RATE` seconds have
    passed since the previous delivery started (injectable `Sleeper`, 001). At 10/s, 10,000
    recipients take about 1,000 s plus the scheduler gaps (≤ 10 s per 290 s run, under 1 minute in
    total), within SC-009's 20 minutes.
  - **Completion**: when no `pending` or `delivering` recipient is left, one transaction sets
    `status = 'completed'`, `running_slot = NULL`, `finished_at` and writes the completion audit
    record with the counts (FR-087), guarded by `status = 'running'` so it happens once.
  - **Cancellation** (`POST …/cancellation`, session only): one transaction locks the job row; a
    completed job → `409` (`about:blank`); a cancelled job → `200` unchanged, not audited; a running
    job → `status = 'cancelled'`, `running_slot = NULL`, `finished_at`, `cancelled_by`, every
    `pending` recipient → `skipped`, and the cancellation audit record with the counts at that
    moment. Because claims lock the same row, no claim can start after the commit; a message already
    being delivered finishes within `RESTV2_MASS_MAIL_DELIVERY_DEADLINE` (5 s), which bounds SC-008.
  - **Recovery** (FR-079): at start, holding the lock, the runner sets every `delivering` recipient
    to `failed` with `failure_reason = 'interrupted'`: its message may or may not have been accepted,
    and at-most-once delivery forbids a retry. A job interrupted by a container restart continues
    with its pending recipients at the next run.
  - **Retention**: `restv2:mass-mail-purge` (daily ofelia job) deletes jobs that are not running and
    were created more than `RESTV2_AUDIT_RETENTION_DAYS` ago; recipients cascade.
- **Rationale**: FR-072 to FR-081, SC-008, SC-009. A row-per-recipient state machine gives
  at-most-once delivery, durable progress, exact counts and per-recipient outcomes without a queue
  broker, and the ofelia runner needs no second container (constitution V, 001-R22).
- **Alternatives considered**: Laravel's Redis queue with one queued job per recipient was
  rejected: at-least-once semantics would re-deliver after a worker crash, a worker needs a
  long-running process (a second container or a supervisor in the php-fpm container), and counts
  would still need a table. One queued job per mass mail was rejected for the same worker reason.
  Synchronous sending (legacy) was rejected by the spec.

### R18. LMTP delivery and message composition

- **Decision**:
  - `App\Clients\Lmtp\LmtpClient` (interface) with `deliver(string $from, string $to, string $rfc822): LmtpResult`
    (`delivered`, `temporaryFailure`, `permanentFailure`, with the reply code and text) and
    `close()`. The adapter `SocketLmtpClient` opens `tcp://RESTV2_LMTP_HOST:RESTV2_LMTP_PORT`
    (`stream_socket_client`, 5 s), reads the `220` greeting, sends `LHLO <MAILCOW_HOSTNAME>`,
    then per message `MAIL FROM:<sender>`, `RCPT TO:<recipient>`, `DATA`, the dot-stuffed message
    with CRLF line endings, `.`, and reads the single per-recipient reply (RFC 2033); `RSET` between
    messages keeps the connection, which is reopened after any error. Every command and the whole
    transaction share the delivery deadline (5 s); a deadline hit before the final `.` aborts
    without delivery (`temporaryFailure`, "timeout"). 2xx = delivered, 4xx or a connection error =
    temporary, 5xx = permanent.
  - Legacy's `sys_mail()` uses PHPMailer in SMTP mode against the same port 24. Dovecot's listener
    there speaks LMTP, which requires `LHLO`; the API speaks LMTP properly. The delivery path,
    target and absence of Postfix and Rspamd are the same as legacy's (clarification).
  - **Message** (`App\Services\MassMail\MassMailMessageFactory`, `symfony/mime`): `From` = sender,
    `To` = the one recipient, `Subject`, `Date`, `Message-ID: <job-id.recipient-id@MAILCOW_HOSTNAME>`,
    `X-Mailer: MooMassMail` (legacy's identification), UTF-8; `multipart/alternative` with the text
    and HTML parts when an HTML body is given, otherwise `text/plain`, as PHPMailer builds it.
  - Tests use `tests/Support/Fakes/FakeLmtpClient` (records messages, programmable replies) in
    feature tests, and a local LMTP stub server (`tests/Support/Lmtp/LmtpStubServer.php`, a child
    process on an ephemeral port) for the adapter's unit tests.
- **Rationale**: FR-076 and the clarification (direct delivery through Dovecot, as legacy).
  `symfony/mime` is already installed with Laravel's mailer; Laravel's `Mail` cannot speak LMTP.
- **Alternatives considered**: PHPMailer (legacy) was rejected: SMTP only, and a second MIME
  library. Symfony Mailer's SMTP transport was rejected: it sends `EHLO`.

### R19. Selection and recipient preview

- **Decision**:
  - **Starting set**: `SELECT username, domain FROM mailbox WHERE kind = '' AND active IN (1, 2)`,
    the mailboxes Dovecot's userdb accepts (`active = '1' OR active = '2'`) minus resources
    (legacy's `kind = ''`). Legacy also includes inactive mailboxes; the spec narrows it (FR-073).
  - **Validation**: domains must exist in `domain` (`notFound`; an alias domain is `notFound`
    too); `include` and `exclude` must not both be non-empty (`mutuallyExclusive`); every listed
    mailbox must be in the starting set (`notFound` when no mailbox row exists, `notEligible` with
    `reason: notReceiving` for a resource or inactive mailbox) and, with `domains`, in those domains
    (`notEligible`, `reason: outsideSelectedDomains`); each list at most 1,000 entries. Addresses and
    domains are compared lower-case; duplicates in a list are ignored.
  - **Preview**: `POST /mass-mail-jobs/recipient-preview` returns the selection's rows sorted by
    address with `ListQuery` paging; `q` filters the listed rows (`username LIKE %q%`), while
    `meta.recipientCount` always counts the whole selection (FR-072 and the orchestrator decision).
    The route carries 005's `restv2.readOnly` marker, so read-only administrator keys may use it,
    and it is not audited. Sessions send the CSRF token as for any POST.
  - **Count check at creation**: the selection is evaluated again inside the creation transaction;
    `count ≠ expectedRecipients` → `409 mass-mail-recipient-count-changed` with `actualRecipients`;
    `count = 0` → `422 tooFew` on `selection`.
- **Rationale**: FR-072 to FR-074; a POST body carries lists far longer than a URL allows.
- **Alternatives considered**: `GET` with repeated query parameters was rejected: 1,000 addresses
  exceed nginx's 8 KiB request line.

---

## 6. Other settings

### R20. License and GUID verification as a server setting

- **Decision**:
  - `RESTV2_LICENSE_VERIFICATION` (default `true`); when `false`, both operations answer
    `403 feature-disabled` (FR-085), and the capabilities report it (FR-094).
  - **GUID**: `versions` row `application = 'GUID'` (001's `SchemaVersion` model), read-only;
    `INVALID` or a missing row → `guid: null`, `guidValid: false`.
  - **Verify**: `POST RESTV2_LICENSE_VERIFY_URL` with the form field `guid` only (legacy's cURL
    post), Laravel HTTP client, connect timeout 10 s, total timeout 20 s. `response: ok` → valid,
    with `c` (customer ID), `s` (service ID) and `m` (`NoMoore` → 1, otherwise the number of `o`);
    `response: invalid` → invalid; anything else, an unreadable body or a connection failure →
    unreachable (not an error, FR-083).
  - **Storage** (legacy's format, so the legacy dashboard shows it): `LICENSE_STATUS_CACHE` =
    `json_encode({valid: "true"|"false", c, s, m})` with legacy's English strings: valid → `c`, `s`
    as returned and `m` as cows (`🐄` repeated); invalid → `"&#10005;"` (a cross mark) three
    times; unreachable → `"Temporary error"` three times (the `en-gb` values of `mailbox.no` and
    `danger.temp_error`). In addition, `restv2:license:last-verification` stores
    `{result, verifiedAt, customerId, serviceId, supportLevel, cacheHash}` where `cacheHash` is the
    SHA-256 of the `LICENSE_STATUS_CACHE` value written.
  - **Read**: when `LICENSE_STATUS_CACHE` is missing → `neverVerified`. When its hash equals
    `cacheHash` → the API record. Otherwise legacy wrote it: `valid: "true"` → valid with `c`, `s`
    and the number of cows in `m`; `valid: "false"` → `unreachable` when `c` equals any language's
    `danger.temp_error` (read from `RESTV2_MAILCOW_LANG_DIR`), else `invalid`; `verifiedAt: null`.
  - The service is contacted only by `POST /license/verification` (FR-084); no scheduler calls it.
- **Rationale**: FR-082 to FR-085, the FeldHost setting (conventions), legacy dashboard parity.

### R21. CORS settings of the legacy API

- **Decision**: `CORS_SETTINGS` hash with `allowed_origins` = origins joined by `", "` and
  `allowed_methods` = methods joined by `", "` (legacy's `implode`). Origins are validated by a port
  of legacy's `valid_origin()` (scheme `http`/`https`, host name or IP, IPv6 in brackets, no
  user, password, path, query or fragment, round-trip equal in lower case) and stored lower-cased,
  deduplicated keeping order; `*` is kept as is. Methods are a non-empty subset of GET, POST, PUT,
  DELETE, stored in the order given without duplicates. Missing or empty origins read as `[]` with
  `serverOrigin = https://<MAILCOW_HOSTNAME>` (legacy's `getBaseURL()` behind mailcow's HTTPS nginx);
  missing methods read as the four methods (legacy's read adds the typo `OPTION`, which the API
  omits). A stored bare host name is returned as stored; because the merged result is validated as a
  whole, it must be corrected in the same request (spec edge case). The tag covers the two raw
  fields.
- **Rationale**: FR-068 to FR-071; this API itself sends no CORS headers (001-R7).

### R22. Presets

- **Decision**: `App\Services\Presets\PresetCatalog` reads `RESTV2_MAILCOW_INC_DIR/presets/{rspamd,sieve}/*.yml`
  (001's mount of `data/web/inc`) with `symfony/yaml`. `id` = file name without `.yml`; a headline
  `lang.<key>` gives `translationKey` = `admin.<key>` (rspamd) or `mailbox.<key>` (sieve), legacy's
  bases, and `headline` = the English text from `RESTV2_MAILCOW_LANG_DIR/lang.en-gb.json` (falling
  back to the key); a literal headline gives `translationKey: null`. `content` as in the file. Sorted
  by `id` with natural order (`sieve_2` before `sieve_10`); `q` over headline and content. Unknown
  kind → 404. Domain administrators get 403 for `rspamd` (spec deviation) and 200 for `sieve`.
- **Rationale**: FR-061; the files of the deployed release, including local changes.
- **Alternatives considered**: `mustangostang/spyc` (legacy's parser) was rejected: unmaintained
  since 2019. A hand-written parser was rejected: YAML block scalars are easy to get wrong.

### R23. Settings capabilities

- **Decision**: `GET /capabilities/settings` (admin, read-only keys allowed) assembles, per request,
  the values of `SettingsCapabilities`: `MAILCOW_HOSTNAME`, `DEMO_MODE`, `RESTV2_LICENSE_VERIFICATION`,
  `RESTV2_RSPAMD_UI_URL`, `MailcowConfig::logoLimits()`, the contrast constants of R5, the field
  limits (constants of `App\Services\Settings\SettingsLimits`, the single source used by the Form
  Requests), and the mass mail policy (`RESTV2_MASS_MAIL_RATE`, 3 retries, 300 s, 1 job, 1,000
  entries, 1 MiB). No secret, no connection detail (no LMTP host, no URLs other than the relative
  Rspamd address).
- **Rationale**: FR-094 (orchestrator decision), on the pattern of 002 FR-064.

---

## 7. Cross-cutting

### R24. Audit and refusal auditing

- **Decision**:
  - **Successes**: one record per state change, written by the service in the same transaction as
    the change (001-R22; R1, R14 for the ordering). Idempotent no-ops (deleting an unset logo,
    cancelling a cancelled job, a PATCH that changes nothing) write none (001-R22). Reads, previews
    and validate-only requests write none (FR-086, FR-093).
  - **Refusals** follow `docs/conventions.md` ("Refusal auditing"): every state-changing route of
    this feature declares `->defaults('restv2.audit', ['event' => <AuditEventType>, 'target' =>
    <AuditTarget type>])`, and 008's `App\Services\Audit\RefusalAuditor` (called from 001's problem
    rendering; or the equivalent the first implemented feature created) writes one failure record
    for every `4xx` problem of such a route when the request has an authenticated principal and the
    type is not `csrf-token-mismatch`. This covers refusals by 001's pipeline (read-only keys,
    `session-required`, restricted sessions) and by this feature (`forbidden`, `404` for
    out-of-scope domains, `412`, `422`, `409`, `demo-mode`, `feature-disabled`). `401`s and CSRF
    failures are never recorded. 013 adds to the auditor:
    - type-specific reasons before the status mapping: `demo-mode` → `demoMode`,
      `feature-disabled` → `featureDisabled`, `regex-map-unavailable` and
      `global-sieve-filter-unavailable` → `fileUnavailable`, `mass-mail-already-running` and
      `mass-mail-recipient-count-changed` → `conflict`;
    - the request attribute `restv2.auditSkip`, set by the Form Requests of the four validate-only
      operations when `validateOnly=true`, so that a refused validate-only request writes no record
      (FR-093);
    - `App\Services\Settings\SettingsAuditTargetResolver`, registered for the 17 target types of
      this feature: the identifier comes from the route parameter (`variant`, `ruleId` with the
      rule's description as name when it exists, `mapId` with the configured name, `filterId`,
      `domain` as sent, normalised and truncated to 255, `jobId` with the subject), and is `null` for
      the singleton settings.
  - **Dependency failures** (`5xx`: `temporary-failure`, `container-action-failed`,
    `outcome-unknown`) are not `4xx`, so the service records them itself, in its own transaction,
    with the reasons `temporaryFailure`, `serviceFailure` and `outcomeUnknown`.
  - Validation refusals record `details.errors` as field names with rule codes, never values (the
    auditor's whitelist).
  - Event types, targets and `details` keys are listed in data-model.md. Large texts (templates,
    maps, rules, scripts, help text, footer) are recorded as `{changed: true, oldBytes, newBytes}`;
    logos as type, dimensions and size; the Rspamd password as `passwordChanged: true`; side effects
    as `dovecotRestart` (`done`/`failed`/`deferred`) and `rspamdConfirmed`.
  - The mass mail completion record has no actor (all actor fields `null`) and carries the creating
    request's `requestId` and client address, so that it is found next to the creation record.
  - **History** (US8, FR-088): clients filter `GET /audit-events` with the generic `targetType` and
    `targetId` parameters (conventions); this feature adds `AuditTarget` values only, no filter
    parameter.
- **Rationale**: FR-086 to FR-088, SC-012 and the conventions' refusal rule, with one mechanism for
  every feature.

### R25. Authorisation and key handling

- **Decision**: Route modes (001-R8) per operation: every read uses `restv2.auth:authenticated`;
  writes use `authenticated` too, so read-write administrator keys may write (FR-004), except
  `setRspamdUiPassword`, `createMassMailJob` and `cancelMassMailJob`, which use `session`
  (FR-006). Read-only keys are refused on writes by 001's `EnforceKeyScope`; the preview carries the
  `restv2.readOnly` marker (R19). Inside controllers, policies (`App\Policies\SettingsPolicy`,
  `QuotaNotificationRecipientsPolicy`, `PresetPolicy`, `MassMailJobPolicy`) require the
  administrator role, except: quota copy recipients (administrators, and domain administrators for
  domains in scope, else 404), sieve presets (administrators and domain administrators). Every
  pending-action mode is `enforce`. Every state-changing route declares `restv2.audit` (R24); the
  preview and the reads do not.
- **Rationale**: FR-001 to FR-006, conventions (keys act as their owner; mass mail and the Rspamd
  password need a session).

### R26. Deployment changes

- **Decision**: 001's deployment files gain (tasks in Setup):
  - `deploy/docker-compose.override.yml`: volumes `./data/conf/rspamd/custom:/mailcow/rspamd_custom_maps:z`,
    `./data/conf/dovecot/global_sieve_before:/mailcow/global_sieve/before:z`,
    `./data/conf/dovecot/global_sieve_after:/mailcow/global_sieve/after:z`,
    `./data/assets/templates:/mailcow/templates:ro,z`, `./data/web/lang:/mailcow/web/lang:ro,z`;
    the environment variables of R2; ofelia labels `restv2_mass_mail` (`@every 10s`, `no-overlap`,
    `php /app/artisan restv2:mass-mail-run --max-time=290`) and `restv2_mass_mail_purge`
    (`@daily`, `php /app/artisan restv2:mass-mail-purge`).
  - `deploy/nginx/site.restv2.custom`: general `client_max_body_size 5m`; nested
    `location ^~ /api/v2/settings/logos/` with `16m` and the same FastCGI lines.
  - `docker/php/Dockerfile`: the `gd` extension (with libpng, libjpeg-turbo, freetype not needed);
    `docker/php/php.ini`: `post_max_size = 20M`.
  - The dev stack (`compose.yaml`) mounts fixture copies of these directories from
    `tests/fixtures/mailcow/` so that manual checks work without a mailcow host.
- **Rationale**: Constitution V (only the API's own service and the documented nginx include are
  touched); the mounts are exactly php-fpm-mailcow's.

### R27. Dependencies (constitution VIII)

| Package / extension | Licence | Scope | Justification |
|---|---|---|---|
| `symfony/yaml` ^7 | MIT | runtime | Parse the shipped preset files (R22) |
| `symfony/mime` ^7 | MIT | runtime | Already installed with Laravel's mailer; required explicitly because 013 uses it directly (R18) |
| PHP extension `gd` | PHP License (bundled) | runtime image | Decode-validate raster logos (R3) |

No package for: SVG inspection (R4), the UCL structure check (R11), the regex line check (R13),
LMTP (R18), WCAG contrast (R5), the Sieve parser (005's legacy copy). All licences are
GPL-3.0-compatible.

### R28. Status codes outside the constitution's list

- **Decision**: `503` (`temporary-failure`, justified by 001 and 002), `502`
  (`container-action-failed`, introduced by 011) and `504` (`outcome-unknown`, introduced by 003)
  are reused with their owners' justification; this feature adds no new status code. `409` is used
  for the two unavailable-file types and the two mass mail conflicts.
- **Rationale**: Consistency with earlier features; RFC 9110 semantics.

### R29. Validate-only requests

- **Decision**: `validateOnly=true` (query parameter) on `createRspamdSettingsRule`,
  `updateRspamdSettingsRule`, `replaceRspamdRegexMap` and `replaceGlobalSieveFilter` runs
  authorisation, `If-Match`, the Form Request and the feature checks (structure, lines, Sieve
  parser; for a regex map also the file's existence, since a real save would refuse it), then
  answers `200 {"data": {"valid": true}}` without writing, restarting or auditing. Read-only keys
  are refused like for the real operation (method-based, 001-R8).
- **Rationale**: FR-093 and the legacy "Validate" buttons.
