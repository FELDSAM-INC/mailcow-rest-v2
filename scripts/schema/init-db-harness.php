<?php

/*
 * Runs upstream mailcow's own init_db_schema() against an empty database (research R2).
 *
 * Executed by scripts/schema/generate-fixture.sh inside the tag's php-fpm-mailcow image, so that
 * the schema is produced by upstream's code at upstream's PHP version. Inputs:
 *
 *   HARNESS_SRC  directory with the tag's init_db.inc.php and vars.inc.php (read-only)
 *   DBHOST, DBNAME, DBUSER, DBPASS  the throwaway MariaDB
 *
 * The trailing `if (php_sapi_name() == "cli") {...}` block of init_db.inc.php (which includes
 * files by absolute path, connects over a socket and flushes memcached) is cut off; the function
 * itself runs unmodified. Exits non-zero unless versions.db_schema ends up at the file's
 * $db_version.
 */

$src = getenv('HARNESS_SRC') ?: '/src';
$initDb = file_get_contents($src.'/init_db.inc.php');

if ($initDb === false) {
    fwrite(STDERR, "init_db.inc.php not found in {$src}\n");
    exit(1);
}

if (! preg_match('/\$db_version\s*=\s*"([^"]+)"/', $initDb, $m)) {
    fwrite(STDERR, "no \$db_version in init_db.inc.php\n");
    exit(1);
}
$dbVersion = $m[1];

// Cut the top-level CLI block (the last line that starts with it at column 0).
$cut = strrpos($initDb, "\nif (php_sapi_name() == \"cli\") {");
if ($cut === false) {
    fwrite(STDERR, "trailing CLI block not found in init_db.inc.php\n");
    exit(1);
}
$function = substr($initDb, 0, $cut)."\n";

// init_db_schema() only runs its migrations on a master.
putenv('MASTER=y');

// vars.inc.php reads HTTP_HOST and defines $MAILBOX_DEFAULT_ATTRIBUTES, which the default
// mailbox template uses. It also sets error_reporting(E_ERROR), as in legacy.
$_SERVER['HTTP_HOST'] = getenv('MAILCOW_HOSTNAME') ?: 'mail.example.org';
require $src.'/vars.inc.php';

$tmp = tempnam(sys_get_temp_dir(), 'init_db_');
file_put_contents($tmp, $function);
require $tmp;
unlink($tmp);

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s', getenv('DBHOST'), getenv('DBNAME')),
    getenv('DBUSER'),
    getenv('DBPASS'),
    [
        // The options of legacy prerequisites.inc.php.
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

init_db_schema();

$version = $pdo->query("SELECT `version` FROM `versions` WHERE `application` = 'db_schema'")->fetchColumn();

if ($version !== $dbVersion) {
    fwrite(STDERR, 'init_db_schema() did not complete: db_schema is '.var_export($version, true).", expected {$dbVersion}\n");
    exit(1);
}

fwrite(STDOUT, "db_version {$dbVersion}\n");
