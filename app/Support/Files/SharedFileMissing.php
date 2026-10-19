<?php

namespace App\Support\Files;

use RuntimeException;

/**
 * A shared mailcow file (a global regex map, a global Sieve file) does not exist, so nothing was
 * written: such files are never created by this API (feature 013 FR-058, FR-064).
 */
class SharedFileMissing extends RuntimeException {}
