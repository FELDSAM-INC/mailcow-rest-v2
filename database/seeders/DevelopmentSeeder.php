<?php

namespace Database\Seeders;

use App\Services\Dkim\DkimKeyGenerator;
use Database\Factories\MailcowRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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
 *  - feature 003: example.org with room for 10 mailboxes and 5 GiB of domain quota (also its
 *    maximum mailbox quota, which legacy requires not to exceed the domain quota), the Default
 *    mailbox template as mailcow seeds it and the template "Small" (1 GiB, POP3 off, sync jobs on);
 *  - feature 008: alice@example.org may use sync jobs (`user_acl.syncjobs = 1`) and has job #1
 *    (never run) and job #2 (last run failed with EXIT_AUTHENTICATION_FAILURE_USER1, with a log);
 *    `carol@example.net` has job #3; `dave@example.org` may not use sync jobs; `reseller` keeps
 *    `da_acl.syncjobs = 1` (the schema default);
 *  - feature 010: the alias domain example.com of example.org, a 2,048-bit DKIM key (selector
 *    `dkim`) for example.org and an unused 1,024-bit key for old.example, in mailcow's Redis
 *    hashes as legacy writes them.
 *  - feature 005: the reset message's sender and subject in mailcow's Redis (`PW_RESET_FROM`,
 *    `PW_RESET_SUBJ`), alice's recovery address, two app passwords of alice (one inactive), a
 *    prefilter of alice and recent logins of alice (IMAP from a public address, a web UI sign-in,
 *    SMTP with an app password); feature 010's alias domain example.com is a temporary-alias domain.
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

        $rows->domain('example.org', attributes: ['mailboxes' => 10, 'quota' => 5120, 'maxquota' => 5120]);
        $rows->domain('example.net');
        $rows->admin('admin', self::PASSWORD);
        $rows->domainAdmin('reseller', ['example.org', 'example.net'], ['protocol_access' => 0], password: self::PASSWORD);
        $rows->mailbox('alice@example.org', self::PASSWORD, userAcl: ['syncjobs' => 1]);
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

        // Feature 008: sync jobs #1 to #3 (quickstart Part B).
        $rows->mailbox('carol@example.net', self::PASSWORD, userAcl: ['syncjobs' => 1]);
        $rows->mailbox('dave@example.org', self::PASSWORD);
        $rows->syncJob('alice@example.org', ['host1' => 'imap.old-provider.example', 'user1' => 'alice.old', 'password1' => 'old-provider-secret']);
        $rows->syncJob('alice@example.org', [
            'host1' => 'imap.old-provider.example',
            'user1' => 'alice.archive',
            'password1' => 'wrong-archive-secret',
            'last_run' => DB::raw('NOW() - INTERVAL 10 MINUTE'),
            'success' => 0,
            'exit_status' => 'EXIT_AUTHENTICATION_FAILURE_USER1',
            'returned_text' => "Transfer started on                     : Sat Oct 18 10:00:00 2026\n"
                ."Host1: imap.old-provider.example says it has NO CAPABILITY for AUTHENTICATE LOGIN\n"
                ."Host1 failure: Error login on [imap.old-provider.example] with user [alice.archive] auth [LOGIN]: 2 NO [AUTHENTICATIONFAILED] Authentication failed.\n"
                ."Exiting with return value 161 (EXIT_AUTHENTICATION_FAILURE_USER1) 0/50 nb_errors/max_errors\n",
        ]);
        $rows->syncJob('carol@example.net', ['host1' => 'mail.previous.example', 'user1' => 'carol', 'password1' => 'previous-secret']);

        // Feature 010: an alias domain, a domain's DKIM key and an unused key (quickstart Part B).
        $rows->aliasDomain('example.com', 'example.org');
        $generator = new DkimKeyGenerator;
        $domainKey = $generator->generate(2048);
        $rows->dkimKeyHashes('example.org', 'dkim', $domainKey->publicKey, $domainKey->privateKey);
        $unusedKey = $generator->generate(1024);
        $rows->dkimKeyHashes('old.example', 'dkim', $unusedKey->publicKey, $unusedKey->privateKey);

        // Feature 005: account self-service (quickstart Part B).
        Redis::connection('mailcow')->mset(['PW_RESET_FROM' => 'noreply@example.org', 'PW_RESET_SUBJ' => 'Reset your password']);
        DB::statement("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.recovery_email', 'alice.private@example.net') WHERE `username` = 'alice@example.org'");
        $phone = $rows->appPasswd('alice@example.org', 'Phone', self::PASSWORD.'-phone');
        $rows->appPasswd('alice@example.org', 'Old laptop', self::PASSWORD.'-laptop', active: 0);
        $rows->sieveFilterRow('alice@example.org', 'Spam to Junk', 'prefilter', "require \"fileinto\";\nif header :contains \"subject\" \"[SPAM]\" {\n  fileinto \"Junk\";\n}");
        $rows->saslLogEntry('alice@example.org', 'imap', '203.0.113.7', (string) DB::scalar('SELECT NOW() - INTERVAL 2 HOUR'));
        $rows->saslLogEntry('alice@example.org', 'WEBUI', '198.51.100.20', (string) DB::scalar('SELECT NOW() - INTERVAL 1 DAY'));
        $rows->saslLogEntry('alice@example.org', 'smtp', '203.0.113.7', (string) DB::scalar('SELECT NOW() - INTERVAL 3 DAY'), $phone);
    }
}
