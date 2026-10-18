<?php

namespace Tests\Feature\Dkim;

use App\Clients\SharedStore\DkimKeyStore;
use App\Services\Dkim\DkimKeyGenerator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 010 T044 (FR-053, research R4): five processes generating a key for the same name at the
 * same moment end with exactly one `created` and four `dkim-key-exists`, and the stored key is the
 * complete key of one generator. The domain row is committed (the workers cannot see a test
 * transaction) and removed again in tearDown().
 */
#[Group('concurrency')]
class DkimConcurrencyTest extends TestCase
{
    /** @var list<string> committed rows instead of one transaction per test */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('domain')->where('domain', 'race.example')->delete();
        DB::table('restv2_audit_events')->where('target_id', 'race.example')->delete();
        $this->redis()->hDel('DKIM_PUB_KEYS', 'race.example');
        $this->redis()->hDel('DKIM_SELECTORS', 'race.example');
        $this->redis()->hDel('DKIM_PRIV_KEYS', 'dkim.race.example');

        parent::tearDown();
    }

    private function redis(): \Redis
    {
        $client = \Illuminate\Support\Facades\Redis::connection('mailcow')->client();
        $this->assertInstanceOf(\Redis::class, $client);

        return $client;
    }

    public function test_exactly_one_of_five_simultaneous_generations_succeeds(): void
    {
        $this->mailcow()->domain('race.example');
        $start = microtime(true) + 3;
        $code = <<<PHP
            while (microtime(true) < {$start}) { usleep(1000); }
            \$principal = new App\\Auth\\Principal('race-admin', App\\Auth\\Role::Admin, App\\Auth\\AccountKind::Admin, App\\Auth\\AuthMethod::Password);
            try {
                \$change = app(App\\Services\\Dkim\\DkimKeyService::class)->generate(\$principal, 'race.example', 'dkim', 1024, false, null);
                echo 'RESULT:'.(\$change->created ? 'created' : 'replaced');
            } catch (App\\Exceptions\\ProblemException \$e) {
                echo 'RESULT:'.\$e->type->value;
            }
            PHP;

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        for ($i = 0; $i < 5; $i++) {
            $process = new Process([$php, base_path('artisan'), 'tinker', '--execute', $code], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            preg_match('/RESULT:([a-zA-Z-]+)/', $process->getOutput(), $m);
            $results[] = $m[1] ?? 'none: '.$process->getOutput().$process->getErrorOutput();
        }

        sort($results);
        $this->assertSame(['created', 'dkim-key-exists', 'dkim-key-exists', 'dkim-key-exists', 'dkim-key-exists'], $results);

        $key = $this->app->make(DkimKeyStore::class)->read('race.example');
        $this->assertNotNull($key);
        $this->assertTrue($key->complete());
        $this->assertSame('dkim', $key->selector);
        // The stored private key belongs to the stored public key: one generator's material.
        $private = (string) $this->redis()->hGet('DKIM_PRIV_KEYS', 'dkim.race.example');
        $this->assertSame($key->publicKey, (new DkimKeyGenerator)->publicKeyOf($private));
        $this->assertSame(1, DB::table('restv2_audit_events')->where('target_id', 'race.example')->where('outcome', 'success')->count());
    }
}
