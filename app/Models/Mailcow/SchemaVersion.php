<?php

namespace App\Models\Mailcow;

/**
 * mailcow `versions`: the row `db_schema` holds the schema version of the installation. Read only.
 *
 * @property string $application
 * @property string $version
 */
class SchemaVersion extends MailcowModel
{
    protected $table = 'versions';

    protected $primaryKey = 'application';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function current(): ?string
    {
        $version = static::query()->whereKey('db_schema')->value('version');

        return is_string($version) ? $version : null;
    }

    /** 2026-11 introduced source-based sync jobs and group-managed alias/sender rows. */
    public static function hasNovember2026Features(): bool
    {
        return self::current() === '02112026_1600';
    }
}
