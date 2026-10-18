# Analysis: feature 005 Account self-service

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Result

- No CRITICAL findings.
- One HIGH finding was fixed: the `PermissionFlagRequired` problem case must be created in T026,
  because 005 is implemented before 006 and 009, which use it.
- Three MEDIUM gaps were fixed:
  - FR-078 (freshness) now has a test;
  - a test asserts the SMTP transport settings;
  - "mailbox settings" is mapped to `mail-settings` in the docs.
- Coverage: every FR (001 to 083) and every buildable SC has at least one task. SC-004 and SC-008
  are partly manual (quickstart Part C).

## Remaining (below HIGH)

- **MEDIUM:** `PUT /me/password` is idempotent only in effect. An identical retry fails the
  current-password check and counts towards throttling.
- **MEDIUM:** `listTemporaryAliasDomains` has a fixed order and no `sort` parameter.
- **LOW:** the reset token check is a POST that, as a read, is not audited. It only increments the
  throttle.
- **LOW:** some edge cases have no test: a recovery address equal to the mailbox's own address, the
  throttle of the WebAuthn reset challenge, and the trusted-vendor roots for WebAuthn.
- **LOW:** temporary-alias address creation uses the domain row lock `FOR UPDATE` that 002 to 004
  share, so it serialises against their address creations. 003's `AddressLock` must stay compatible
  with it. Other code-only uniqueness rules use `GET_LOCK`.

## Orchestrator decisions on owner questions

All accepted:
1. Webmail SSO couples to the legacy PHP session format, as the spec sanctions. The session has no
   role and read-only API access.
2. The reset link path is `RESTV2_PW_RESET_PAGE=/ng/reset-password?token={token}`. mailcowui-ng
   serves the route, and UI 014 maps legacy reset links to it.
3. The legacy Sieve parser is copied unmodified from the upstream tag, under mailcow's GPL-3.0.
4. Only `requiredFlags` is used, with `requiredFlagsMatch`.
5. The Apple profile with calendars and contacts is refused when the groupware is disabled.
