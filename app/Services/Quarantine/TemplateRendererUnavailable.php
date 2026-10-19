<?php

namespace App\Services\Quarantine;

use RuntimeException;

/**
 * The template check could not run at all (no Python interpreter, or an answer that is not the
 * renderer's): an unexpected failure, answered `500`.
 */
class TemplateRendererUnavailable extends RuntimeException {}
