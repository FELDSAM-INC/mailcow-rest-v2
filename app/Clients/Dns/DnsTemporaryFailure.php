<?php

namespace App\Clients\Dns;

use RuntimeException;

/**
 * A DNS lookup failed temporarily (SERVFAIL, timeout, unreachable resolver), as opposed to a name
 * without such records (feature 009 research R15). Forwarding host creation answers it with
 * `503 temporary-failure` (`dependency: dns`); a connection test reports it as its result.
 */
class DnsTemporaryFailure extends RuntimeException {}
