<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of a mass mail job (`restv2_mass_mail_recipients`, feature 013 FR-077, FR-079):
 * `pending` → `delivering` → `delivered`, `failed` or, after a cancellation, `skipped`
 * (data-model.md section 4). `delivering` is exposed as `pending`.
 *
 * @property int $id
 * @property string $job_id
 * @property string $address
 * @property string $outcome
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_attempt_at
 */
class MassMailRecipient extends Model
{
    public const PENDING = 'pending';

    public const DELIVERING = 'delivering';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'restv2_mass_mail_recipients';

    public $timestamps = false;

    // Explicit service-owned fields: future columns are not mass assignable by default.
    protected $fillable = [
        'id',
        'job_id',
        'address',
        'outcome',
        'failure_code',
        'failure_reason',
        'attempts',
        'next_attempt_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }
}
