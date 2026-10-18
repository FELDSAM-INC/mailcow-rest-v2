<?php

/*
 * What legacy mailbox('add', 'domain') of mailcow-dockerized writes for three inputs, derived
 * line by line from the source (feature 002 T051, SC-002). Each value names the legacy line it
 * comes from.
 *
 * Source: data/web/inc/functions.mailbox.inc.php at tag 2026-09a
 * Source SHA-256: 861b3b408818e5ba575022c3102dc42eab5bb82996906e96093aa9293d81917a
 *
 * Templates are the rows `templates` holds before the creation: `Default` exactly as
 * init_db_schema() seeds it (init_db.inc.php 1426-1446), `Reseller` as legacy's add-template form
 * stores it (functions.mailbox.inc.php 1684-1700).
 *
 * Copyright (c) The mailcow team and contributors. GPL-3.0.
 */

return [
    'default template' => [
        // The legacy form fields (add-domain modal) and the API body for the same input.
        'legacy' => ['domain' => 'parity-a.example'],
        'api' => ['name' => 'parity-a.example'],
        'template' => 'Default',
        'domain' => [
            'domain' => 'parity-a.example',        // 508: idn_to_ascii(strtolower(trim($_data['domain'])))
            'description' => 'parity-a.example',   // 509-510: empty description => $domain
            'aliases' => 400,                      // 512: template max_num_aliases_for_domain
            'mailboxes' => 10,                     // 513: template max_num_mboxes_for_domain
            'defquota' => 3072,                    // 514: def_quota_for_mbox / 1024 ** 2
            'maxquota' => 10240,                   // 515: max_quota_for_mbox / 1024 ** 2
            'quota' => 10240,                      // 517: max_quota_for_domain / 1024 ** 2
            'relayhost' => '0',                    // 610: not in the column list, column default
            'backupmx' => 0,                       // 555: template backupmx
            'gal' => 1,                            // 556: template gal
            'relay_all_recipients' => 0,           // 553
            'relay_unknown_only' => 0,             // 554
            'active' => 1,                         // 552
        ],
        'tags' => [],                              // 511: template tags
        'domain_map' => '1',                       // 645: hSet('DOMAIN_MAP', $domain, 1)
        'rl_value' => null,                        // 656-659: template rl_value '' => no ratelimit('edit')
        'dkim_selector' => 'dkim',                 // 661-671: template dkim_selector, dkim('add')
        'restarts' => [],                          // 516, 674: restart_sogo not set
    ],
    'template with overrides and rate limit' => [
        'legacy' => ['domain' => 'Parity-B.example', 'template' => 'Reseller', 'mailboxes' => 20, 'description' => 'Parity B', 'tags' => ['silver']],
        'api' => ['name' => 'Parity-B.example', 'templateId' => 'Reseller', 'maxMailboxes' => 20, 'description' => 'Parity B', 'tags' => ['silver']],
        'template' => 'Reseller',
        'domain' => [
            'domain' => 'parity-b.example',        // 508: lower-cased
            'description' => 'Parity B',           // 509
            'aliases' => 400,                      // 512: template
            'mailboxes' => 20,                     // 513: request
            'defquota' => 3072,                    // 514: template
            'maxquota' => 10240,                   // 515: template
            'quota' => 51200,                      // 517: template max_quota_for_domain 51200 MiB
            'relayhost' => '0',
            'backupmx' => 0,
            'gal' => 1,
            'relay_all_recipients' => 0,
            'relay_unknown_only' => 0,
            'active' => 1,
        ],
        'tags' => ['silver'],                      // 511: request tags replace the template's
        'domain_map' => '1',                       // 645
        'rl_value' => '10 / 1h',                   // 656-659 and functions.ratelimit.inc.php: "$rl_value / 1$rl_frame"
        'dkim_selector' => 'res',                  // 662: template dkim_selector
        'restarts' => [],
    ],
    'relay options with a groupware restart' => [
        'legacy' => ['domain' => 'parity-c.example', 'relay_unknown_only' => 1, 'restart_sogo' => 1],
        'api' => ['name' => 'parity-c.example', 'relayUnknownOnly' => true, 'restartGroupware' => true],
        'template' => 'Default',
        'domain' => [
            'domain' => 'parity-c.example',
            'description' => 'parity-c.example',
            'aliases' => 400,
            'mailboxes' => 10,
            'defquota' => 3072,
            'maxquota' => 10240,
            'quota' => 10240,
            'relayhost' => '0',
            'backupmx' => 1,                       // 561-563: relay_unknown_only => backupmx = 1
            'gal' => 1,
            'relay_all_recipients' => 1,           // 561-563: relay_unknown_only => relay_all_recipients = 1
            'relay_unknown_only' => 1,             // 554
            'active' => 1,
        ],
        'tags' => [],
        'domain_map' => '1',
        'rl_value' => null,
        'dkim_selector' => 'dkim',
        'restarts' => ['sogo-mailcow'],            // 674-675: docker('post', 'sogo-mailcow', 'restart')
    ],
];
