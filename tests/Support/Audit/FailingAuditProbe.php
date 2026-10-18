<?php

namespace Tests\Support\Audit;

use App\Services\Audit\AuditLogger;
use App\Services\Audit\IrreversibleOperationAudit;
use App\Services\Config\MailcowConfig;
use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * An irreversible-operation audit whose probe fails, as when the audit table cannot be read
 * (feature 011 T040, T065, T078): the operation must be refused before anything is done.
 */
class FailingAuditProbe extends IrreversibleOperationAudit
{
    public static function bind(Application $app): void
    {
        $app->instance(IrreversibleOperationAudit::class, new self(
            $app->make(AuditLogger::class),
            $app->make(Sleeper::class),
            $app->make(Clock::class),
            $app->make(MailcowConfig::class),
        ));
    }

    protected function probeQuery(): void
    {
        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }
}
