# Contracts of feature 008

The contract of this feature is not duplicated here. The OpenAPI 3.1 document in the repository's
[`api/`](../../../api/) directory is the single source of truth (constitution I).

- [`api/openapi.yaml`](../../../api/openapi.yaml): the tag `Sync jobs` and the six path
  references (`/sync-jobs…` and `/capabilities/sync-jobs`).
- [`api/modules/sync-jobs/`](../../../api/modules/sync-jobs/): one kebab-case file per path, and
  the module's `_index.yaml`:
  - `sync-jobs.yaml`: `GET /sync-jobs` (listSyncJobs) and `POST /sync-jobs` (createSyncJob);
  - `sync-jobs-capabilities.yaml`: `GET /capabilities/sync-jobs` (getSyncJobsCapabilities), the
    project-wide `/capabilities/{area}` pattern of `docs/conventions.md`;
  - `sync-jobs-bulk-actions.yaml`: `POST /sync-jobs/bulk-actions` (applySyncJobBulkAction);
  - `sync-job.yaml`: `GET`, `PATCH` and `DELETE /sync-jobs/{syncJobId}` (getSyncJob,
    updateSyncJob, deleteSyncJob);
  - `sync-job-log.yaml`: `GET /sync-jobs/{syncJobId}/log` (getSyncJobLog);
  - `sync-job-schedule-reset.yaml`: `POST /sync-jobs/{syncJobId}/schedule-reset`
    (rescheduleSyncJob).
- Components, all named after the resource:
  - parameters: `SyncJobId`, `SyncJobSort`;
  - schemas: `SyncJob`, `SyncJobRunStatus`, `SyncJobLastResult`, `SyncJobEncryption`,
    `SyncJobCreateRequest`, `SyncJobPatch`, `SyncJobLog`, `SyncJobCapabilities`, `SyncJobDefaults`,
    `SyncJobRange`, `SyncJobBulkAction`, `SyncJobBulkTarget`, `SyncJobBulkActionRequest`;
  - responses: `SyncJobResponse`, `SyncJobCreatedResponse`, `SyncJobListResponse`,
    `SyncJobLogResponse`, `SyncJobCapabilitiesResponse`, `SyncJobExists`,
    `SyncJobValidationFailed`.
- Shared components reused: `ListMeta`, `Problem`, `ValidationProblem`, `Limit`, `Offset`, `Order`,
  `Search`, `IfMatch`, `BulkTarget` (extended by `SyncJobBulkTarget`), `BulkResultResponse`, the
  security schemes and the standard responses (`BadRequest`, `Unauthorized`, `Forbidden`,
  `NotFound`, `PreconditionFailed`, `ValidationFailed`, `NoContent`, `InternalError`).
- Values appended to the shared enumerations:
  - `KnownProblemType`: `/api/v2/problems/sync-job-exists`;
  - `AuditEventType`: `syncJobCreation`, `syncJobUpdate`, `syncJobReschedule`, `syncJobDeletion`;
  - `AuditTarget.type`: `syncJob`;
  - `AuditFailureReason`: `permissionDenied`, `notFound`;
  - `ValidationRuleCode`: `optionNotAllowed`, `optionContainsSpace`, `customParamsNotPermitted`,
    `invalidRegex`, `immutable`.
- [`docs/problems.md`](../../../docs/problems.md#sync-job-exists): the `sync-job-exists` problem
  type and how the other sync-job errors map.

History (FR-026) uses the generic `targetType` and `targetId` filters of `GET /audit-events`; this
feature adds no audit filter (`docs/conventions.md`).

The operations, with their authentication and the requirements they serve, are listed in
[plan.md](../plan.md#contract-operations).

Check the contract with:

```bash
npx --yes @redocly/cli@2.64.1 lint api/openapi.yaml
npx --yes @redocly/cli@2.64.1 bundle api/openapi.yaml -o build/contract/openapi.yaml
```
