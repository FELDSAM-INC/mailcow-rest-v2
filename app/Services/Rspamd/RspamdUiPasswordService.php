<?php

namespace App\Services\Rspamd;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ProblemType;

/**
 * The Rspamd web interface password (feature 013 FR-066, FR-067, research R16): handed to mailcow's
 * container service with legacy's exact call, `exec('rspamd-mailcow', {cmd: rspamd, task:
 * worker_password, raw})`; dockerapi runs `rspamadm pw`, stores the hash for Rspamd and restarts it.
 *
 * Secrecy: the password is never stored, logged, audited or returned. The service's own message is
 * not relayed (it might echo the command), the audit record only says that the password changed,
 * and a refusal carries no details.
 */
class RspamdUiPasswordService
{
    public const CONTAINER = 'rspamd-mailcow';

    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ProblemException `502 container-action-failed` or `504 outcome-unknown`
     */
    public function change(#[\SensitiveParameter] string $password, Principal $actor): void
    {
        $result = $this->docker->exec(self::CONTAINER, ['cmd' => 'rspamd', 'task' => 'worker_password', 'raw' => $password]);

        if ($result->isUnknown()) {
            throw new ProblemException(ProblemType::OutcomeUnknown, "mailcow's container service did not answer in time; whether the password was set is unknown. Setting it again is safe.", ['step' => 'rspamdUiPassword']);
        }

        if (! $result->isSuccess()) {
            throw new ProblemException(ProblemType::ContainerActionFailed, $result->isUnreachable()
                ? 'The Rspamd container could not be reached; the password was not changed.'
                : 'mailcow\'s container service could not set the password or restart Rspamd; the password may have been stored and would then apply at Rspamd\'s next start.', ['container' => self::CONTAINER]);
        }

        $this->audit->record(AuditEventType::RspamdUiPasswordChange, true, $actor, ['type' => 'rspamdUiPassword', 'id' => null, 'name' => null], null, null, ['passwordChanged' => true, 'rspamdConfirmed' => true]);
    }
}
