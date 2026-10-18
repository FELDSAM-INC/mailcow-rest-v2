<?php

namespace Tests\Feature\SingleSignOn;

use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Mailboxes\MailboxCreation;
use App\Services\Mailboxes\MailboxCreator;
use App\Support\Database\NamedLock;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T052 (FR-040): two first sign-ins of the same new user create exactly one mailbox, and both sign
 * in.
 */
class ConcurrentFirstSignInTest extends TestCase
{
    use SingleSignOnTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->keycloakConfig();
    }

    public function test_a_mailbox_created_while_waiting_for_the_lock_is_used(): void
    {
        $locks = new \ArrayObject;
        // The other sign-in held the provisioning lock and created the mailbox meanwhile.
        $this->app->instance(NamedLock::class, new class($locks) extends NamedLock
        {
            /** @param \ArrayObject<int, string> $locks */
            public function __construct(private readonly \ArrayObject $locks) {}

            public function run(array $names, Closure $code, int $timeout = self::DEFAULT_TIMEOUT): mixed
            {
                foreach ($names as $name) {
                    $this->locks->append($name.'/'.$timeout);

                    if (str_starts_with($name, 'restv2_idp_')) {
                        DB::table('mailbox')->insert(['username' => 'ben@example.org', 'password' => '', 'name' => 'Ben', 'quota' => 0, 'local_part' => 'ben', 'domain' => 'example.org', 'attributes' => '{}', 'authsource' => 'keycloak', 'active' => 1]);
                    }
                }

                return parent::run($names, $code, $timeout);
            }
        });
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated()->assertJsonPath('data.username', 'ben@example.org');

        $this->assertContains('restv2_idp_'.sha1('ben@example.org').'/10', $locks->getArrayCopy());
        $this->assertSame(1, DB::table('mailbox')->where('username', 'ben@example.org')->count());
        $this->assertFalse($this->signInAudit()[0]->details['mailboxCreated']);
    }

    public function test_an_address_taken_despite_the_lock_continues_as_an_existing_mailbox(): void
    {
        $creator = $this->app->make(MailboxCreator::class);
        $this->app->instance(MailboxCreator::class, new class extends MailboxCreator
        {
            public function __construct() {}

            public function create(Principal $principal, array $input, array $options = []): MailboxCreation
            {
                DB::table('mailbox')->insert(['username' => 'ben@example.org', 'password' => '', 'name' => 'Ben', 'quota' => 0, 'local_part' => 'ben', 'domain' => 'example.org', 'attributes' => '{}', 'authsource' => 'keycloak', 'active' => 1]);

                throw new ProblemException(ProblemType::AddressInUse);
            }
        });
        unset($creator);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated();

        $this->assertSame(1, DB::table('mailbox')->where('username', 'ben@example.org')->count());
    }
}
