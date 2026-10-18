<?php

namespace App\Models\Mailcow;

use LogicException;

/**
 * mailcow `logs`: the legacy UI and `/api/v1` activity log (feature 011, data model). Read only:
 * this API never writes legacy logs (FR-068); its own actions go to `restv2_audit_events`.
 *
 * @property int $id
 * @property string $task
 * @property string|null $type
 * @property string|null $msg
 * @property string|null $call
 * @property string $user
 * @property string $role
 * @property string $remote
 * @property int $time
 */
class LegacyUiLogEntry extends MailcowModel
{
    protected $table = 'logs';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('The legacy activity log is read only.'));
        static::deleting(fn (): never => throw new LogicException('The legacy activity log is read only.'));
    }

    protected function casts(): array
    {
        return ['id' => 'integer', 'time' => 'integer'];
    }
}
