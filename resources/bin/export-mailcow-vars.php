<?php

/*
 * Exports values of mailcow's PHP configuration as JSON (research R14).
 *
 *   php -n -d display_errors=stderr resources/bin/export-mailcow-vars.php <dir> <hostname> <NAME>...
 *
 * Reproduces legacy's include sequence (prerequisites.inc.php): HTTP_HOST is set to the mailcow
 * host name, which vars.inc.php reads, then <dir>/vars.inc.php and, when it exists,
 * <dir>/vars.local.inc.php are included in the global scope. Prints the requested variables that
 * are defined. Runs in its own process because vars.inc.php changes error_reporting and defines
 * globals. A parse error exits non-zero.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: export-mailcow-vars.php <dir> <hostname> <NAME>...\n");
    exit(64);
}

$__restv2_dir = rtrim($argv[1], '/');
$__restv2_names = array_slice($argv, 3);
$_SERVER['HTTP_HOST'] = $argv[2];

if (! is_file($__restv2_dir.'/vars.inc.php')) {
    fwrite(STDERR, "{$__restv2_dir}/vars.inc.php not found\n");
    exit(66);
}

ob_start();
require $__restv2_dir.'/vars.inc.php';

if (is_file($__restv2_dir.'/vars.local.inc.php')) {
    include $__restv2_dir.'/vars.local.inc.php';
}
ob_end_clean();

$__restv2_values = [];

foreach ($__restv2_names as $__restv2_name) {
    if (array_key_exists($__restv2_name, $GLOBALS)) {
        $__restv2_values[$__restv2_name] = $GLOBALS[$__restv2_name];
    }
}

echo json_encode((object) $__restv2_values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
