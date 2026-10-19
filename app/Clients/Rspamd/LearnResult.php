<?php

namespace App\Clients\Rspamd;

/**
 * The classified answer of a learning or fuzzy call of the Rspamd controller (feature 006 research
 * R2): `done` (2xx without error), `alreadyLearned` (HTTP 208, or an error saying "already
 * learned", which legacy counts as success), `noFuzzyContent` (the message has no content to
 * generate fuzzy hashes from) and `failed` (any other error, status, connection failure or timeout).
 */
enum LearnResult: string
{
    case Done = 'done';
    case AlreadyLearned = 'alreadyLearned';
    case NoFuzzyContent = 'noFuzzyContent';
    case Failed = 'failed';
}
