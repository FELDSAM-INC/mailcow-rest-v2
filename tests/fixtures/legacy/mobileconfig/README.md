# Legacy Apple connection profiles (feature 005 T120)

`2026-09a-email.plist` and `2026-09a-full.plist` are the output of the template body of legacy
`data/web/mobileconfig.php` at upstream tag 2026-09a (the part from `echo '<?xml`), run unmodified
by `scripts/legacy/render-mobileconfig-fixtures.sh 2026-09a` with these fixed values:

- address `anna@example.org`, display name `Anna & <B>` (escaped by legacy's `htmlspecialchars`);
- product name (`$UI_TEXTS['main_name']`) `mailcow UI`;
- IMAP `mail.example.org:993`, SMTP `mail.example.org:465`, CalDAV and CardDAV `mail.example.org:443`;
- no app password; every `getGUID()` answers `00000000-0000-4000-8000-000000000000`.

`email` is legacy's `?only_email`, `full` the default. `tests/Feature/OwnAccount/AppleProfileTest.php`
compares the API's profile with them element by element after replacing the payload UUIDs.
