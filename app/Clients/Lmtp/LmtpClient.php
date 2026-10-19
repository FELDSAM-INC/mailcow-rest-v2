<?php

namespace App\Clients\Lmtp;

/**
 * Dovecot's local delivery over LMTP (feature 013 research R18): the path legacy's sys_mail()
 * delivers mass mail on (`dovecot-mailcow`, port 24), so that messages never pass Postfix or
 * Rspamd. One message per call, addressed to one recipient. Faked in tests.
 */
interface LmtpClient
{
    /**
     * Delivers one RFC 5322 message (CRLF or LF line endings) to one recipient within the delivery
     * deadline. Never throws: a connection failure or a deadline hit before the message was
     * completed is a temporary failure.
     */
    public function deliver(string $from, string $to, string $message): LmtpResult;

    /** Ends the connection (`QUIT`), if one is open. */
    public function close(): void;
}
