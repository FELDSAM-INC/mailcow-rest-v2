<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Clients\Smtp\SmtpProbeRequest;
use App\Clients\Smtp\StreamSmtpProbe;
use App\Services\DnsDiagnostics\TlsaDigest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\Smtp\ScriptedSmtpServer;

/**
 * Feature 010 T054 (FR-042, research R19): the expected TLSA value is `3 1 1` and the SHA-256 of
 * the DER SubjectPublicKeyInfo of the certificate presented after STARTTLS, as legacy
 * generate_tlsa_digest() computes it; checked against OpenSSL's own digest of the scripted server's
 * certificate.
 */
class TlsaDigestTest extends TestCase
{
    public function test_the_digest_of_the_certificate_presented_after_starttls(): void
    {
        $server = new ScriptedSmtpServer([
            ['send' => '220 mx.test.example ESMTP'], ['expect' => 'EHLO'], ['send' => '250-mx.test.example'], ['send' => '250 STARTTLS'],
            ['expect' => 'STARTTLS'], ['send' => '220 Ready'], ['starttls' => true], ['expect' => 'QUIT'], ['send' => '221 Bye'],
        ], true);

        try {
            $result = (new StreamSmtpProbe)->probe(new SmtpProbeRequest('mx.test.example', ['127.0.0.1'], $server->port(), 'mail.example.org', null, null, '', '', 5, 5, stopAfterTls: true, capturePeerCertificate: true));
            $pem = (string) $server->certificatePem();
        } finally {
            $server->stop();
        }

        $file = (string) tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($file, $pem);
        $openssl = Process::fromShellCommandline('openssl x509 -in "$CERT" -noout -pubkey | openssl pkey -pubin -outform DER | openssl dgst -sha256 -r');
        $openssl->run(null, ['CERT' => $file]);
        @unlink($file);
        $this->assertTrue($openssl->isSuccessful(), $openssl->getErrorOutput());

        $expected = '3 1 1 '.strtok(trim($openssl->getOutput()), ' ');

        $this->assertSame($expected, TlsaDigest::fromCertificate((string) $result->peerCertificate));
        $this->assertSame($expected, TlsaDigest::fromCertificate($pem));
    }

    public function test_text_that_is_not_a_certificate_has_no_digest(): void
    {
        $this->assertNull(TlsaDigest::fromCertificate('not a certificate'));
    }
}
