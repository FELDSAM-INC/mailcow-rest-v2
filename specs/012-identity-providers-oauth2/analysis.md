# Analysis: feature 012 Identity providers and OAuth2

The plan agent ran `/speckit-analyze` over `spec.md`, `plan.md` and `tasks.md`. The orchestrator
recorded the results here, because the harness would not let the subagent write this file.

## Coverage

- All 64 FRs and all 9 buildable SCs map to tasks. SC-006 is an admin-UI target and cannot be built
  in the API.
- 0 CRITICAL and 0 HIGH findings remain.

## HIGH findings fixed

- Provider calls could have run inside 001's sign-in database transaction.
- Two concurrent first saves could both create the configuration. They are now serialised with
  `GET_LOCK`.
- The setup flow could not get the return addresses before the first save. They now come from the
  capabilities read.
- A feature-specific audit filter was removed, per the conventions.
- Version tags used plain hashes of secrets. They now use an HMAC.
- Refusals by read-only keys and restricted sessions were not audited.
- Some FR references were missing from the tasks.

## Remaining

- **MEDIUM:**
  - M1 to M3 concern the UI repository: UI 012 serves `/ng/sso/return`, keeps passkey sign-in under
    force-SSO (already aligned), and filters sign-ins by method on the client.
  - M4: 003's service names stand in as `MailboxService::create()/update()`. Tasks resolve them
    against 003's plan.
  - M5: the sync status depends on the 2026-09a log texts. A script-extracted fixture catches
    changes per release.
  - M6: US3 depends on the mailbox provisioner built in US2.
  - M7: the stored-settings parity test uses hand-derived legacy rows. Quickstart Part C checks
    against real legacy.
- **LOW:**
  - provider tokens live in a separate Redis key;
  - `permissionDenied` is reused for role refusals;
  - the generic OIDC connection test may appear as a failed attempt in the provider's own logs;
  - the read-latency target in the plan has no test.

## Orchestrator decisions on owner questions

All accepted as the agent defaulted them:
- the return path is `/ng/sso/return`, configurable with `RESTV2_SSO_RETURN_PATH`;
- force-SSO is display-only and keeps passkeys;
- the sync status is exposed with its limits documented.
