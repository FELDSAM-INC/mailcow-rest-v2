<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * mailcow `templates` rows of `type = 'domain'` (mailbox templates are invisible here). Written
 * only by App\Services\DomainTemplates\DomainTemplateService.
 *
 * @property int $id
 * @property string $template
 * @property string $type
 * @property string|null $attributes
 * @property CarbonImmutable|null $created
 * @property CarbonImmutable|null $modified
 */
class DomainTemplate extends MailcowModel
{
    protected $table = 'templates';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return [
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('domain', fn (Builder $query) => $query->where('type', 'domain'));
    }
}
