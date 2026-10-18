<?php

namespace App\Auth;

/**
 * How the caller authenticated (contract `AuthMethod`).
 */
enum AuthMethod: string
{
    case Password = 'password';
    case PasswordAndSecondFactor = 'passwordAndSecondFactor';
    case Passkey = 'passkey';
    case ApiKey = 'apiKey';
}
