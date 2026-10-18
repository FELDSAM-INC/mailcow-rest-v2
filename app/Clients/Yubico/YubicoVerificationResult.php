<?php

namespace App\Clients\Yubico;

/**
 * Outcome of a Yubico OTP verification (R11).
 */
enum YubicoVerificationResult
{
    /** The service accepted the OTP (`status=OK`, signed, echoing the OTP and nonce). */
    case Valid;

    /** The OTP was rejected (replayed, bad OTP, unknown client, bad signature, ...). */
    case Invalid;

    /** The service could not be reached or could not answer: try again later. */
    case TemporaryFailure;
}
