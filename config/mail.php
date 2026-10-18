<?php

use App\Support\Config\Env;

/*
|--------------------------------------------------------------------------
| Mail (feature 005 research R6)
|--------------------------------------------------------------------------
|
| The API sends one kind of message: the password reset message, through mailcow's mail server
| without authentication, with legacy PHPMailer's settings of send_mail(): opportunistic STARTTLS
| without peer verification and a 10-second timeout. The framework's other mailers (`array`, `log`,
| ...) stay available; the tests use `array` (MAIL_MAILER in .env.testing).
|
*/

return [

    'default' => Env::get('MAIL_MAILER', 'restv2_postfix'),

    'mailers' => [

        'restv2_postfix' => [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => (string) Env::get('RESTV2_SMTP_HOST', 'postfix-mailcow'),
            'port' => (int) Env::get('RESTV2_SMTP_PORT', 25),
            'username' => null,
            'password' => null,
            'timeout' => 10,
            'local_domain' => Env::get('MAILCOW_HOSTNAME'),
            'auto_tls' => true,
            'verify_peer' => false,
        ],

    ],

    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS', 'noreply@localhost'),
        'name' => Env::get('MAIL_FROM_NAME', 'mailcow'),
    ],

];
