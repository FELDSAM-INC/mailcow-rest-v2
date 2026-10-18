<?php

namespace Tests\Unit\Clients;

use App\Clients\DockerApi\DockerCallResult;
use App\Clients\Dovecot\DockerApiSieveScriptReader;
use App\Clients\Dovecot\SieveScriptUnavailable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fakes\FakeDockerApiClient;

/**
 * T108 (feature 005 FR-053, research R13): the active script through dockerapi, as legacy
 * mailbox('get', 'active_user_sieve') reads it.
 */
class DockerApiSieveScriptReaderTest extends TestCase
{
    public function test_the_active_script_is_printed_without_its_first_line(): void
    {
        $docker = new FakeDockerApiClient;
        $docker->withExec('dovecot-mailcow', 'sieve', 'list', "other\nroundcube ACTIVE\n");
        $docker->withExec('dovecot-mailcow', 'sieve', 'print', "# roundcube\nrequire \"vacation\";\nvacation \"Away\";\n");

        $script = (new DockerApiSieveScriptReader($docker))->active('anna@example.org');

        $this->assertSame('roundcube', $script->name);
        $this->assertSame("require \"vacation\";\nvacation \"Away\";\n", $script->script);
        $docker->assertExecOrder([
            ['dovecot-mailcow', ['cmd' => 'sieve', 'task' => 'list', 'username' => 'anna@example.org']],
            ['dovecot-mailcow', ['cmd' => 'sieve', 'task' => 'print', 'script_name' => 'roundcube', 'username' => 'anna@example.org']],
        ]);
    }

    public function test_no_active_line(): void
    {
        $docker = new FakeDockerApiClient;
        $docker->withExec('dovecot-mailcow', 'sieve', 'list', "roundcube\nmanaged\n");

        $script = (new DockerApiSieveScriptReader($docker))->active('anna@example.org');

        $this->assertNull($script->name);
        $this->assertNull($script->script);
        $docker->assertCallCount('execText', 1);
    }

    public function test_an_empty_list(): void
    {
        $docker = new FakeDockerApiClient;
        $docker->withExec('dovecot-mailcow', 'sieve', 'list', '');

        $this->assertNull((new DockerApiSieveScriptReader($docker))->active('anna@example.org')->name);
    }

    public function test_unreachable_and_timeout(): void
    {
        foreach (['unreachable', 'timeout'] as $mode) {
            $docker = (new FakeDockerApiClient)->failNext('execText', $mode);

            try {
                (new DockerApiSieveScriptReader($docker))->active('anna@example.org');
                $this->fail('No exception for '.$mode);
            } catch (SieveScriptUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_failed_print(): void
    {
        $docker = new FakeDockerApiClient;
        $docker->withExec('dovecot-mailcow', 'sieve', 'list', "roundcube ACTIVE\n");
        $docker->withExec('dovecot-mailcow', 'sieve', 'print', DockerCallResult::failed('No container.'));

        $this->expectException(SieveScriptUnavailable::class);
        (new DockerApiSieveScriptReader($docker))->active('anna@example.org');
    }
}
