<?php

namespace Database\Seeders;

use Database\Factories\MailcowRows;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The accounts of quickstart.md Part B for the dev stack (task T111), written exactly as legacy
 * writes them (Database\Factories\MailcowRows). Every password is `Passw0rd!dev`.
 *
 *  - `admin`: administrator, BLF-CRYPT, no second factor;
 *  - `reseller`: domain administrator of example.org and example.net, without the
 *    `protocol_access` flag (feature 003 quickstart Part B);
 *  - `alice@example.org`: mailbox with the TOTP secret JBSWY3DPEHPK3PXP;
 *  - `bob@example.org`: mailbox that mailcow forces to change its password;
 *  - the Default domain template as mailcow seeds it (feature 002 quickstart Part B);
 *  - feature 003: example.org with room for 10 mailboxes and 5 GiB of domain quota, the Default
 *    mailbox template as mailcow seeds it and the template "Small" (1 GiB, POP3 off, sync jobs on).
 *
 * Run by scripts/dev/reset-dev-database.sh. It refuses to run outside APP_ENV=local, so that it
 * can never write accounts into a real mailcow database.
 */
class DevelopmentSeeder extends Seeder
{
    public const PASSWORD = 'Passw0rd!dev';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevelopmentSeeder only runs with APP_ENV=local.');
        }

        $rows = new MailcowRows;

        $rows->domain('example.org', attributes: ['mailboxes' => 10, 'quota' => 5120]);
        $rows->domain('example.net');
        $rows->admin('admin', self::PASSWORD);
        $rows->domainAdmin('reseller', ['example.org', 'example.net'], ['protocol_access' => 0], password: self::PASSWORD);
        $rows->mailbox('alice@example.org', self::PASSWORD);
        $rows->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP', 'Phone');
        $rows->mailbox('bob@example.org', self::PASSWORD, attributes: ['force_pw_update' => '1']);
        $rows->defaultDomainTemplate();
        $rows->defaultMailboxTemplate();
        $rows->mailboxTemplate('Small', array_merge(MailcowRows::DEFAULT_MAILBOX_TEMPLATE, [
            'quota' => 1073741824,
            'tagged_mail_handler' => 'none',
            'pop3_access' => 0,
            'acl_syncjobs' => 1,
            'acl_pw_reset' => 1,
        ]));
    }
}
