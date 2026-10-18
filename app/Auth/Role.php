<?php

namespace App\Auth;

/**
 * Account role (contract `Role`). Admins and domain admins are rows of mailcow's `admin` table
 * (`superadmin` 1 or 0); mailbox users are rows of `mailbox`.
 */
enum Role: string
{
    case Admin = 'admin';
    case DomainAdmin = 'domainAdmin';
    case MailboxUser = 'mailboxUser';

    public function accountKind(): AccountKind
    {
        return $this === self::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin;
    }

    /**
     * The role name legacy mailcow uses (`admin`, `domainadmin`, `user`).
     */
    public function legacyName(): string
    {
        return match ($this) {
            self::Admin => 'admin',
            self::DomainAdmin => 'domainadmin',
            self::MailboxUser => 'user',
        };
    }
}
