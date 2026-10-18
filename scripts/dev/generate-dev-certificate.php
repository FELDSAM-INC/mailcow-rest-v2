<?php

/*
 * Generates a self-signed TLS certificate for the dev stack's HTTPS listener (compose service
 * `certs`, quickstart Part B) and for scripts/dev/verify-nginx-snippet.sh. The certificate is never
 * committed: the dev stack keeps it in the Docker volume `dev-certs`.
 *
 *   php scripts/dev/generate-dev-certificate.php <directory> [cert file] [key file]
 *
 * An existing certificate is kept. Uses PHP's openssl extension, so no openssl binary is needed.
 */

$directory = rtrim($argv[1] ?? '', '/');
$certFile = $directory.'/'.($argv[2] ?? 'dev.crt');
$keyFile = $directory.'/'.($argv[3] ?? 'dev.key');

if ($directory === '' || ! is_dir($directory)) {
    fwrite(STDERR, "usage: php scripts/dev/generate-dev-certificate.php <directory> [cert file] [key file]\n");
    exit(2);
}

if (is_file($certFile) && is_file($keyFile)) {
    echo "kept existing certificate {$certFile}\n";
    exit(0);
}

$config = tempnam(sys_get_temp_dir(), 'restv2-openssl-');
file_put_contents($config, <<<'CNF'
    [req]
    distinguished_name = dn
    prompt = no
    [dn]
    CN = localhost
    O = mailcow REST API v2 development
    [v3]
    basicConstraints = critical, CA:FALSE
    keyUsage = critical, digitalSignature, keyEncipherment
    extendedKeyUsage = serverAuth
    subjectAltName = DNS:localhost, DNS:mail.example.org, IP:127.0.0.1, IP:::1
    CNF);

$options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
$csr = $key === false ? false : openssl_csr_new(['commonName' => 'localhost'], $key, $options);
$certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 825, $options, random_int(1, PHP_INT_MAX));
$exported = $certificate !== false
    && openssl_x509_export($certificate, $certPem)
    && openssl_pkey_export($key, $keyPem, null, ['config' => $config]);

unlink($config);

if (! $exported) {
    fwrite(STDERR, 'could not generate the certificate: '.openssl_error_string()."\n");
    exit(1);
}

file_put_contents($keyFile, $keyPem);
chmod($keyFile, 0644);
file_put_contents($certFile, $certPem);
echo "generated {$certFile}\n";
