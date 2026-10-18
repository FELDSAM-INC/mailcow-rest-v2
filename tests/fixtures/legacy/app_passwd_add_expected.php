<?php

/*
 * What legacy app_passwd('add') of mailcow-dockerized writes for two inputs, derived line by line
 * from the source (feature 005 T065, SC-009). Each value names the legacy line it comes from. The
 * `id`, the `password` hash and the timestamps are generated and not compared.
 *
 * Source: data/web/inc/functions.app_passwd.inc.php at tag 2026-09a
 * Source SHA-256: 00e7b70092f94b9b41a5e13ab301c7dfb7cbe9be32a61f7d2247f4f70baef2c0
 *
 * Copyright (c) The mailcow team and contributors. GPL-3.0.
 */

return [
    'two protocols' => [
        // The legacy form fields (add app password modal, all fields as the UI posts them) and the
        // API body for the same input.
        'legacy' => ['app_name' => ' A&B <Phone> ', 'protocols' => ['imap_access', 'smtp_access'], 'active' => '1'],
        'api' => ['name' => ' A&B <Phone> ', 'password' => 'AppPassw0rd!x', 'protocols' => ['imap', 'smtp'], 'active' => true],
        'row' => [
            'name' => 'A&amp;B &lt;Phone&gt;',   // 26: htmlspecialchars(trim($_data['app_name']))
            'mailbox' => 'anna@example.org',     // 18, 22: the given username, or the signed-in one
            'domain' => 'example.org',           // 37: mailbox('get', 'mailbox_details')['domain']
            'imap_access' => 1,                  // 31: in_array('imap_access', $protocols)
            'smtp_access' => 1,                  // 33
            'eas_access' => 0,                   // 34
            'dav_access' => 0,                   // 32
            'pop3_access' => 0,                  // 35
            'sieve_access' => 0,                 // 36
            'active' => 1,                       // 29: intval($_data['active'])
        ],
    ],
    'all protocols, inactive' => [
        'legacy' => ['app_name' => 'Laptop', 'protocols' => ['imap_access', 'dav_access', 'smtp_access', 'eas_access', 'pop3_access', 'sieve_access'], 'active' => '0'],
        'api' => ['name' => 'Laptop', 'password' => 'AppPassw0rd!x', 'protocols' => ['imap', 'pop3', 'smtp', 'sieve', 'activeSync', 'dav'], 'active' => false],
        'row' => [
            'name' => 'Laptop',
            'mailbox' => 'anna@example.org',
            'domain' => 'example.org',
            'imap_access' => 1,
            'smtp_access' => 1,
            'eas_access' => 1,
            'dav_access' => 1,
            'pop3_access' => 1,
            'sieve_access' => 1,
            'active' => 0,
        ],
    ],
];
