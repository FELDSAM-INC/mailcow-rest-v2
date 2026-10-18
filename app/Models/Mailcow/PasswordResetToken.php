<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `reset_password`: password reset tokens, shared with the legacy reset page (feature 005
 * FR-021). Read only through this model; App\Services\PasswordReset\PasswordResetService writes the
 * table with legacy's statements. The token is never logged or returned.
 *
 * @property string $username
 * @property string $token
 */
class PasswordResetToken extends MailcowModel
{
    protected $table = 'reset_password';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['created' => MailcowDateTime::class];
    }
}
