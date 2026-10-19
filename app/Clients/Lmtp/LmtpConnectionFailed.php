<?php

namespace App\Clients\Lmtp;

use RuntimeException;

/**
 * Internal to SocketLmtpClient: the connection failed, broke or passed the delivery deadline. The
 * client reports it as a temporary failure and never throws it.
 */
class LmtpConnectionFailed extends RuntimeException {}
