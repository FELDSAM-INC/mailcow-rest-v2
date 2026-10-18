<?php

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

    'default' => env('MAIL_MAILER', 'restv2_postfix'),

    'mailers' => [

        'restv2_postfix' => [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => (string) env('RESTV2_SMTP_HOST', 'postfix-mailcow'),
            'port' => (int) env('RESTV2_SMTP_PORT', 25),
            'username' => null,
            'password' => null,
            'timeout' => 10,
            'local_domain' => env('MAILCOW_HOSTNAME'),
            'auto_tls' => true,
            'verify_peer' => false,
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@localhost'),
        'name' => env('MAIL_FROM_NAME', 'mailcow'),
    ],

];
