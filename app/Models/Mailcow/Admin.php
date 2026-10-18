<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `admin`: administrators (`superadmin = 1`) and domain administrators (`superadmin = 0`).
 * Read only.
 *
 * @property string $username
 * @property string $password
 * @property bool $superadmin
 * @property bool $active
 * @property array<string, mixed>|null $attributes
 */
class Admin extends MailcowModel
{
    protected $table = 'admin';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'superadmin' => 'boolean',
            'active' => 'boolean',
            'attributes' => 'array',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
