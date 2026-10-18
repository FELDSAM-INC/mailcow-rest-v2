<?php

namespace App\Clients\Ldap;

/**
 * The outcome of a directory call (research R2, R11).
 */
enum LdapStatus: string
{
    /** The test bind succeeded, or the user's entry was found and the bind with the password succeeded. */
    case Ok = 'ok';

    /** The user's entry was found, but the bind with the password failed (result code 49). */
    case WrongPassword = 'wrongPassword';

    /** No entry matches the username. */
    case UnknownUser = 'unknownUser';

    /** The service account (bind DN and password) was refused (result code 49). */
    case CredentialsRejected = 'credentialsRejected';

    /** The directory could not be reached. */
    case Unreachable = 'unreachable';

    /** No answer within the time-out. */
    case TimedOut = 'timedOut';

    /** The TLS handshake (LDAPS or StartTLS) or the certificate check failed. */
    case TlsFailure = 'tlsFailure';

    /** Any other result code (in `code`). */
    case Unexpected = 'unexpected';

    /** Whether the directory itself failed (not the user's password or name). */
    public function isUnavailable(): bool
    {
        return ! in_array($this, [self::Ok, self::WrongPassword, self::UnknownUser], true);
    }
}
