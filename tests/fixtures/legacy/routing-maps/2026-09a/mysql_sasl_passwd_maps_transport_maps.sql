-- Extracted by scripts/legacy/extract-routing-maps.sh from mailcow-dockerized 2026-09a.
-- Source: data/Dockerfiles/postfix/postfix.sh, mysql_sasl_passwd_maps_transport_maps.cf (SHA-256 0e3f00788f0acfd3b6a71af9570a3fd5df8c0fad1016eee623a24eafe6ace8f4).
-- Do not edit: regenerate with the script.
SELECT CONCAT_WS(':', username, password) AS auth_data FROM transports
  WHERE nexthop = '%s'
  AND active = '1'
  AND username != ''
  LIMIT 1;
