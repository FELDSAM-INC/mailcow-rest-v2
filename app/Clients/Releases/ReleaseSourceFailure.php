<?php

namespace App\Clients\Releases;

use RuntimeException;

/** The release source failed or answered without the documented members (feature 011 R10). */
class ReleaseSourceFailure extends RuntimeException {}
