<?php

namespace App\Clients\DockerApi;

use RuntimeException;

/**
 * A read of mailcow's container service failed before an answer arrived: connection refused, DNS
 * or TLS failure, or an answer that is not the documented document (feature 011 research R2).
 * Rendered as `503 temporary-failure`; nothing was changed.
 */
class DockerApiUnreachable extends RuntimeException {}
