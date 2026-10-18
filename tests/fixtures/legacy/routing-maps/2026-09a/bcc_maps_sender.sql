-- Extracted by scripts/legacy/extract-routing-maps.sh from mailcow-dockerized 2026-09a.
-- Source: data/conf/rspamd/dynmaps/bcc.php (sender) (SHA-256 f64841d92c09e8c2fbfc8f3faf76f52b9a90c7ae392ae865ce22b75aeec70068).
-- Do not edit: regenerate with the script.
SELECT `bcc_dest` FROM `bcc_maps` WHERE `type` = 'sender' AND `local_dest` = :local_dest AND `active` = '1'
