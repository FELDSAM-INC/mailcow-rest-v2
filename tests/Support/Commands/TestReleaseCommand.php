<?php

namespace Tests\Support\Commands;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Mail\QuarantineReleaseMailer;
use App\Clients\Rspamd\RspamdController;
use App\Exceptions\ProblemException;
use App\Services\Quarantine\QuarantineActionService;
use App\Support\Http\ProblemType;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Mail\FakeQuarantineReleaseMailer;
use Tests\Support\Rspamd\FakeRspamdController;

/**
 * Test-only command of feature 006 T044/T049 (registered in the `testing` environment only):
 * releases the given quarantine items one after the other as an administrator, with the release
 * and Rspamd fakes appending their calls to shared files, and prints how many attempts were done,
 * busy (`409 quarantine-item-busy`) or not found, as JSON. Several of these processes race for the
 * same items in QuarantineConcurrencyTest.
 */
class TestReleaseCommand extends Command
{
    protected $signature = 'restv2:test-release {ids* : Quarantine item IDs, in the order to release}
        {--delay=0 : Milliseconds each delivery takes}
        {--mailer-log= : File the deliveries are appended to}
        {--rspamd-log= : File the spam filter calls are appended to}
        {--actor=admin : The administrator who releases}';

    protected $description = 'Release quarantine items with faked outbound services (tests only).';

    public function handle(): int
    {
        $mailer = (new FakeQuarantineReleaseMailer)->delay((int) $this->option('delay'));
        $rspamd = new FakeRspamdController;

        if (is_string($this->option('mailer-log'))) {
            $mailer->logTo($this->option('mailer-log'));
        }

        if (is_string($this->option('rspamd-log'))) {
            $rspamd->logTo($this->option('rspamd-log'));
        }

        $this->laravel->instance(QuarantineReleaseMailer::class, $mailer);
        $this->laravel->instance(RspamdController::class, $rspamd);
        $actions = $this->laravel->make(QuarantineActionService::class);
        $actor = new Principal((string) $this->option('actor'), Role::Admin, Role::Admin->accountKind(), AuthMethod::Password);
        $counts = ['done' => 0, 'busy' => 0, 'notFound' => 0];

        foreach ((array) $this->argument('ids') as $id) {
            try {
                $actions->release($actor, (int) $id, true, null);
                $counts['done']++;
            } catch (ProblemException $e) {
                if ($e->type !== ProblemType::QuarantineItemBusy) {
                    throw $e;
                }

                $counts['busy']++;
            } catch (NotFoundHttpException) {
                $counts['notFound']++;
            }
        }

        $this->line((string) json_encode($counts));

        return self::SUCCESS;
    }
}
