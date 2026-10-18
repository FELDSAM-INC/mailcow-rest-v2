<?php

namespace App\Exceptions;

/**
 * The mail store (mailcow's container service or the Dovecot container) could not be reached at all,
 * as opposed to reporting a failure. Rendered as `MailboxDependencyUnavailable` (`503`,
 * `dependency: mailStore`); bulk actions use the difference to stop calling the mail store for the
 * rest of a request.
 */
class MailStoreUnreachable extends MailboxDependencyUnavailable {}
