<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `fido2`: passkeys. The table has no primary key; a row is identified by `username` plus
 * SHA2(credentialId, 256), the contract's Passkey.id. Read only: App\Services\Passkeys writes it
 * with the query builder.
 *
 * @property string $username
 * @property string|null $friendlyName
 * @property string $rpId
 * @property string $credentialPublicKey
 * @property string|null $certificateSubject
 * @property string|null $AAGUID
 * @property string $credentialId
 * @property bool $active
 */
class Fido2Credential extends MailcowModel
{
    protected $table = 'fido2';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $hidden = ['credentialPublicKey', 'certificateChain', 'certificate', 'credentialId', 'AAGUID'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'signatureCounter' => 'integer',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
