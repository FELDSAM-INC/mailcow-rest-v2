<?php

namespace App\Clients\Dovecot;

use RuntimeException;

/** The mail server could not be asked for a Sieve script (feature 005 FR-053). */
final class SieveScriptUnavailable extends RuntimeException {}
