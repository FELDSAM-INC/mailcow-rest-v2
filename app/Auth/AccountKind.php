<?php

namespace App\Auth;

/**
 * The mailcow table that holds an account: `admin` (admins and domain admins) or `mailbox`.
 */
enum AccountKind: string
{
    case Admin = 'admin';
    case Mailbox = 'mailbox';
}
