<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * mailcow `templates` rows of `type = 'mailbox'`: the values new mailboxes start with (feature 003
 * FR-049). Read only; app/Services/Mailboxes/MailboxTemplateService writes them.
 *
 * @property int $id
 * @property string $template
 * @property string $type
 * @property string|null $attributes
 */
class MailboxTemplate extends MailcowModel
{
    protected $table = 'templates';

    protected $primaryKey = 'id';

    protected static function booted(): void
    {
        static::addGlobalScope('mailbox', fn (Builder $query) => $query->where('type', 'mailbox'));
    }

    protected function casts(): array
    {
        return [
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
