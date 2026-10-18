<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use App\Services\SyncJobs\SyncJobEncryption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * mailcow `imapsync`: a sync job that pulls mail from a remote IMAP account into a local mailbox
 * (feature 008 data-model.md). Read through App\Services\SyncJobs\SyncJobQuery and SyncJobScope;
 * written only by App\Services\SyncJobs\SyncJobService. The remote password and the run log are
 * hidden from serialisation: the password is write-only (FR-018), the log is read on its own.
 *
 * @property int $id
 * @property string $user2
 * @property string $host1
 * @property string $user1
 * @property string $password1
 * @property string $exclude
 * @property string $subfolder2
 * @property int $maxage
 * @property int $mins_interval
 * @property int $maxbytespersecond
 * @property int $port1
 * @property SyncJobEncryption|null $enc1
 * @property bool $delete2duplicates
 * @property bool $delete1
 * @property bool $delete2
 * @property bool $automap
 * @property bool $skipcrossduplicates
 * @property string $custom_params
 * @property int $timeout1
 * @property int $timeout2
 * @property bool $subscribeall
 * @property bool $dry
 * @property bool $is_running
 * @property string|null $returned_text
 * @property CarbonImmutable|null $last_run
 * @property int|null $success
 * @property string|null $exit_status
 * @property CarbonImmutable|null $created
 * @property CarbonImmutable|null $modified
 * @property bool $active
 */
class SyncJob extends MailcowModel
{
    protected $table = 'imapsync';

    protected $primaryKey = 'id';

    protected $hidden = ['password1', 'returned_text'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'enc1' => SyncJobEncryption::class,
            'delete2duplicates' => 'boolean',
            'delete1' => 'boolean',
            'delete2' => 'boolean',
            'automap' => 'boolean',
            'skipcrossduplicates' => 'boolean',
            'subscribeall' => 'boolean',
            'dry' => 'boolean',
            'is_running' => 'boolean',
            'active' => 'boolean',
            'maxbytespersecond' => 'integer',
            'maxage' => 'integer',
            'mins_interval' => 'integer',
            'port1' => 'integer',
            'timeout1' => 'integer',
            'timeout2' => 'integer',
            'success' => 'integer',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
            'last_run' => MailcowDateTime::class,
        ];
    }

    /**
     * The target mailbox (`user2`).
     *
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'user2', 'username');
    }
}
