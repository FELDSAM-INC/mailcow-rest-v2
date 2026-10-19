<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A mass mail job (`restv2_mass_mail_jobs`, feature 013 FR-074 to FR-080). Written only by
 * App\Services\MassMail\MassMailJobService and MassMailRunner; its stored fields change only
 * through its delivery progress and cancellation.
 *
 * @property string $id
 * @property string $status
 * @property int|null $running_slot
 * @property string $sender
 * @property string $subject
 * @property string $text_body
 * @property string|null $html_body
 * @property array<string, list<string>> $selection
 * @property int $recipient_count
 * @property string $created_by
 * @property string $created_request_id
 * @property string $created_client_address
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $cancelled_by
 */
class MassMailJob extends Model
{
    use HasUlids;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $table = 'restv2_mass_mail_jobs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'running_slot' => 'integer',
            'selection' => 'array',
            'recipient_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** Upper-case ULIDs, as every API-owned identifier (contract `MassMailJob.id`). */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * @return HasMany<MassMailRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(MassMailRecipient::class, 'job_id');
    }
}
