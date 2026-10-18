<?php

/*
 * The SQL statements of legacy mailbox('get', 'domain_details'), copied verbatim.
 *
 * Source: data/web/inc/functions.mailbox.inc.php at tag 2026-09a, lines 5033-5160
 * Source SHA-256: 861b3b408818e5ba575022c3102dc42eab5bb82996906e96093aa9293d81917a
 *
 * Copyright (c) The mailcow team and contributors.
 * This program is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation, version 3.
 * It is distributed WITHOUT ANY WARRANTY; see the GNU General Public License for details.
 *
 * Feature 002 T039 (SC-001) runs them on the same data as the API and compares every figure.
 */

return [
    'domain' => "SELECT
              `domain`,
              `description`,
              `aliases`,
              `mailboxes`,
              `defquota`,
              `maxquota`,
              `created`,
              `modified`,
              `quota`,
              `relayhost`,
              `relay_all_recipients`,
              `relay_unknown_only`,
              `backupmx`,
              `gal`,
              `active`
                FROM `domain` WHERE `domain`= :domain",

    'mailboxes' => "SELECT COUNT(`username`) AS `count`,
            COALESCE(SUM(`quota`), 0) AS `in_use`
              FROM `mailbox`
                WHERE (`kind` = '' OR `kind` = NULL)
                  AND `domain` = :domain",

    'quota' => "SELECT SUM(bytes) AS `bytes_total`, SUM(messages) AS `msgs_total` FROM `quota2`
            WHERE `username` IN (
              SELECT `username` FROM `mailbox`
                WHERE `domain` = :domain
            );",

    'aliases' => "SELECT COUNT(`address`) AS `alias_count` FROM `alias`
            WHERE (`domain`= :domain OR `domain` IN (SELECT `alias_domain` FROM `alias_domain` WHERE `target_domain` = :domain2))
              AND `address` NOT IN (
                SELECT `username` FROM `mailbox`
              )",
];
