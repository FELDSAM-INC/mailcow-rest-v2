-- Extracted by scripts/legacy/extract-routing-maps.sh from mailcow-dockerized 2026-09a.
-- Source: data/Dockerfiles/postfix/postfix.sh, mysql_sasl_passwd_maps_sender_dependent.cf (SHA-256 0e3f00788f0acfd3b6a71af9570a3fd5df8c0fad1016eee623a24eafe6ace8f4).
-- Do not edit: regenerate with the script.
SELECT CONCAT_WS(':', username, password) AS auth_data FROM relayhosts
  WHERE id IN (
    SELECT COALESCE(
      (SELECT id FROM relayhosts
      LEFT OUTER JOIN domain ON domain.relayhost = relayhosts.id
      WHERE relayhosts.active = '1'
        AND (domain.domain = '%d'
          OR domain.domain IN (
            SELECT target_domain FROM alias_domain
            WHERE alias_domain = '%d'
          )
        )
      ),
      (SELECT id FROM relayhosts
      LEFT OUTER JOIN mailbox ON JSON_UNQUOTE(JSON_VALUE(mailbox.attributes, '$.relayhost')) = relayhosts.id
      WHERE relayhosts.active = '1'
        AND (
          mailbox.username IN (
            SELECT alias.goto from alias
              JOIN mailbox ON mailbox.username = alias.goto
                WHERE alias.active = '1'
                  AND alias.address = '%s'
                  AND alias.address NOT LIKE '@%%'
          )
        )
      )
    )
  )
  AND active = '1'
  AND username != '';
