<?php

use App\Services\Settings\SettingsLimits;
use Sieve\SieveParser;

// Invoked by SieveValidator with time and memory limits. No Laravel bootstrap or inherited
// application environment is needed. Script contents are data, never PHP source or arguments.
error_reporting(0);
require dirname(__DIR__, 2).'/app/Services/Settings/SettingsLimits.php';
require __DIR__.'/../legacy/sieve/SieveParser.php';

$script = stream_get_contents(STDIN, SettingsLimits::SIEVE_SCRIPT_MAX_BYTES + 1);
if ($script === false || strlen($script) > SettingsLimits::SIEVE_SCRIPT_MAX_BYTES) {
    exit(2);
}

$error = null;
try {
    (new SieveParser)->parse($script);
} catch (Exception $exception) {
    $error = $exception->getMessage();
}

echo json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
