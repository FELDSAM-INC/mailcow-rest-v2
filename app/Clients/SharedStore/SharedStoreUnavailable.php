<?php

namespace App\Clients\SharedStore;

use RuntimeException;

/**
 * mailcow's shared store (Redis) cannot be reached or refused a command. Rendered as
 * `503 temporary-failure` with `Retry-After: 10` (feature 002 FR-056).
 */
class SharedStoreUnavailable extends RuntimeException {}
