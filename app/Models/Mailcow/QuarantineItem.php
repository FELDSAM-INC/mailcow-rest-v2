<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use App\Support\Http\ETag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * mailcow `quarantine`: messages the spam filter kept for one recipient mailbox (feature 006
 * data-model). Written by the legacy ingestion (`pipe.php`); this API only deletes rows, through
 * App\Services\Quarantine\QuarantineActionService. The stored message (`msg`) and the quick-action
 * hash (`qhash`, the secret of the legacy notification links) are never serialised, and lists
 * select every column except `msg` (withoutMessage()).
 *
 * @property int $id
 * @property string $qid
 * @property string|null $subject
 * @property float|null $score
 * @property string $action
 * @property list<array<string, mixed>>|null $symbols
 * @property list<string>|null $fuzzy_hashes
 * @property string $sender
 * @property string|null $rcpt
 * @property string|null $msg
 * @property bool $notified
 * @property CarbonImmutable|null $created
 */
class QuarantineItem extends MailcowModel
{
    /** Every column except the stored message. */
    public const SUMMARY_COLUMNS = ['id', 'qid', 'subject', 'score', 'ip', 'action', 'symbols', 'fuzzy_hashes', 'sender', 'rcpt', 'domain', 'notified', 'created', 'user', 'qhash'];

    protected $table = 'quarantine';

    protected $primaryKey = 'id';

    protected $hidden = ['msg', 'qhash'];

    protected function casts(): array
    {
        return [
            'created' => MailcowDateTime::class,
            'symbols' => 'array',
            'fuzzy_hashes' => 'array',
            'notified' => 'bool',
            'score' => 'float',
        ];
    }

    /**
     * The item's version tag (feature 006 research R17): over its identifying stored values
     * (`id|qid|rcpt|created`), never over `notified` (runtime state) or `qhash` (a secret). It stays
     * the same for as long as the item exists.
     */
    public function versionTag(): string
    {
        return ETag::of(['quarantineItem', (int) $this->getAttribute('id'), (string) $this->getRawOriginal('qid'), $this->getRawOriginal('rcpt'), $this->getRawOriginal('created')]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutMessage(Builder $query): Builder
    {
        return $query->select(array_map(fn (string $column): string => 'quarantine.'.$column, self::SUMMARY_COLUMNS));
    }
}
