<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * A MariaDB named lock was not acquired within its timeout: another request holds it for too long.
 * Rendered as an unexpected failure (`500`).
 */
class LockNotAcquired extends RuntimeException {}
