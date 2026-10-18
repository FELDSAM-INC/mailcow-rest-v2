<?php

namespace App\Clients\Dovecot;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;

/**
 * legacy mailbox('get', 'active_user_sieve') over 002's dockerapi client (`DOCKER_TIMEOUT`):
 * `doveadm sieve list` (`{cmd: sieve, task: list}`), then, for the first line that ends in
 * ` ACTIVE`, `doveadm sieve get` (`{cmd: sieve, task: print}`) with the script name, without the
 * first output line.
 */
class DockerApiSieveScriptReader implements SieveScriptReader
{
    private const SERVICE = 'dovecot-mailcow';

    public function __construct(private readonly DockerApiClient $docker) {}

    public function active(string $mailbox): ActiveSieveScript
    {
        $list = $this->text($this->docker->execText(self::SERVICE, ['cmd' => 'sieve', 'task' => 'list', 'username' => $mailbox]));

        foreach (array_filter(preg_split('/(\r\n|\n|\r)/', $list) ?: []) as $line) {
            if (preg_match('/.+ ACTIVE/i', $line) === 1) {
                $name = substr($line, 0, -7);
                $script = $this->text($this->docker->execText(self::SERVICE, ['cmd' => 'sieve', 'task' => 'print', 'script_name' => $name, 'username' => $mailbox]));

                return new ActiveSieveScript($name, (string) preg_replace('/^.+\n/', '', $script));
            }
        }

        return ActiveSieveScript::none();
    }

    private function text(DockerCallResult $result): string
    {
        if (! $result->isSuccess()) {
            throw new SieveScriptUnavailable($result->isUnknown()
                ? 'The mail server did not answer in time.'
                : 'The mail server cannot be reached: '.$result->message);
        }

        return is_string($result->data) ? $result->data : '';
    }
}
