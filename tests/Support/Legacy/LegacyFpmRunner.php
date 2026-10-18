<?php

namespace Tests\Support\Legacy;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs legacy mailcow's unmodified `sogo-auth.php` (tests/fixtures/legacy/sogo-auth/<tag>/) under a
 * throw-away php-fpm pool and asks it, through `cgi-fcgi`, what nginx-mailcow's `auth_request` for
 * `/SOGo/so/` would ask (feature 005 research R3). The pool keeps PHP sessions in the test Redis
 * database (phpredis, `PHPREDIS_SESSION:` keys), exactly where the webmail hand-off writes them.
 */
final class LegacyFpmRunner
{
    private const PORT = 9901;

    private ?Process $fpm = null;

    private string $dir;

    public function __construct(private readonly string $tag = '2026-09a')
    {
        $this->dir = sys_get_temp_dir().'/restv2-fpm-'.bin2hex(random_bytes(6));
    }

    public function start(): void
    {
        $redis = config('database.redis.mailcow');
        $savePath = sprintf('tcp://%s:%d?auth=%s&database=%d', $redis['host'], (int) $redis['port'], rawurlencode((string) $redis['password']), (int) $redis['database']);

        if (! is_dir($this->dir) && ! mkdir($this->dir, 0700, true)) {
            throw new RuntimeException("Cannot create {$this->dir}.");
        }

        file_put_contents($this->dir.'/php-fpm.conf', implode("\n", [
            '[global]',
            'pid = '.$this->dir.'/php-fpm.pid',
            'error_log = '.$this->dir.'/php-fpm.log',
            'daemonize = no',
            '[legacy]',
            'listen = 127.0.0.1:'.self::PORT,
            'pm = static',
            'pm.max_children = 1',
            'clear_env = no',
            'catch_workers_output = yes',
            'php_admin_value[session.save_handler] = redis',
            'php_admin_value[session.save_path] = "'.$savePath.'"',
            'php_admin_value[session.serialize_handler] = php',
            'php_admin_value[display_errors] = Off',
            'php_admin_value[error_log] = '.$this->dir.'/php.log',
            '',
        ]));

        $this->fpm = new Process(['php-fpm', '-F', '-y', $this->dir.'/php-fpm.conf', '-c', '/usr/local/etc/php/php.ini-production']);
        $this->fpm->setTimeout(null);
        $this->fpm->start();

        $deadline = microtime(true) + 10;

        while (@fsockopen('127.0.0.1', self::PORT) === false) {
            if (! $this->fpm->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('php-fpm did not start: '.$this->fpm->getErrorOutput().$this->fpm->getOutput().@file_get_contents($this->dir.'/php-fpm.log'));
            }

            usleep(20_000);
        }
    }

    /**
     * The headers legacy sogo-auth.php answers for a webmail request with the given cookies.
     *
     * @param  array<string, string>  $cookies
     * @return array<string, string> header name (lower case) => value
     */
    public function webmailAuthRequest(array $cookies, string $userAgent = 'Symfony'): array
    {
        $root = base_path('tests/fixtures/legacy/sogo-auth/'.$this->tag);
        $cookie = implode('; ', array_map(fn (string $name, string $value): string => $name.'='.$value, array_keys($cookies), $cookies));

        $process = new Process(['cgi-fcgi', '-bind', '-connect', '127.0.0.1:'.self::PORT], null, [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $root.'/sogo-auth.php',
            'SCRIPT_NAME' => '/sogo-auth.php',
            'DOCUMENT_ROOT' => $root,
            'QUERY_STRING' => '',
            'REQUEST_URI' => '/sogo-auth.php',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'mail.example.org',
            'HTTPS' => 'on',
            'SOGO_AUTH_INTERNAL' => '1',
            'HTTP_X_ORIGINAL_URI' => '/SOGo/so/',
            'HTTP_COOKIE' => $cookie,
            'HTTP_USER_AGENT' => $userAgent,
            'MAILCOW_HOSTNAME' => 'mail.example.org',
        ]);
        $process->mustRun();

        $headers = [];

        foreach (preg_split('/\r?\n/', $process->getOutput()) ?: [] as $line) {
            if ($line === '') {
                break;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }

    public function stop(): void
    {
        if ($this->fpm !== null && $this->fpm->isRunning()) {
            $this->fpm->stop(1);
        }

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }
}
