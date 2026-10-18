<?php

namespace App\Clients\Rspamd;

use RuntimeException;

/**
 * The Rspamd controller could not be reached, did not answer in time, answered with an error status
 * or with a document that is not JSON. Callers render it as `503 temporary-failure` (features 006
 * and 011).
 */
class RspamdUnavailable extends RuntimeException {}
