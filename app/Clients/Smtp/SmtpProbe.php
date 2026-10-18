<?php

namespace App\Clients\Smtp;

/**
 * The SMTP dialogue of a relayhost or transport connection test (feature 009 FR-042, FR-043,
 * research R13), shared with later features. Never throws for a failed test: the result says where
 * and why it stopped. Faked in feature tests (Tests\Support\Smtp\FakeSmtpProbe).
 */
interface SmtpProbe
{
    public function probe(SmtpProbeRequest $request): SmtpProbeResult;
}
