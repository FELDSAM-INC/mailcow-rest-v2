<?php

/*
 * Feature 007 T072 (SC-003): a race worker of LastActiveAdminConcurrencyTest. It boots the
 * application once (APP_ENV=testing, its own database connection outside the test's transaction),
 * then takes one job per round from the Redis list `restv2:race:<worker>`, waits for the job's start
 * time, deactivates or deletes the target administrator through AdminAccountService on behalf of
 * the actor, and pushes the outcome to `restv2:race:<worker>:results`. A job `{"stop": true}` ends it.
 *
 *   php tests/bin/admin-race.php <worker>
 */

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Accounts\AdminAccountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$worker = (string) ($argv[1] ?? 'worker');
$redis = Redis::connection('restv2');
$redis->rpush("race:{$worker}:ready", '1');

// Redis connections time out reads after 5 s, so the worker polls in short blocking steps and
// gives up after two idle minutes.
$idleSince = microtime(true);

while (true) {
    $job = $redis->blpop(["race:{$worker}"], 2);

    if (! is_array($job) || ! isset($job[1])) {
        if (microtime(true) - $idleSince > 120) {
            break;
        }

        continue;
    }

    $idleSince = microtime(true);

    $data = json_decode((string) $job[1], true);

    if (! is_array($data) || ($data['stop'] ?? false) === true) {
        break;
    }

    while (microtime(true) < (float) $data['start']) {
        usleep(100);
    }

    try {
        $actor = new Principal((string) $data['actor'], Role::Admin, AccountKind::Admin, AuthMethod::Password);
        $service = $app->make(AdminAccountService::class);

        if ($data['action'] === 'delete') {
            $service->delete($actor, (string) $data['target'], null);
        } else {
            $service->update($actor, (string) $data['target'], ['active' => false], null);
        }

        $result = 'done';
    } catch (ProblemException $e) {
        $result = $e->type->value;
    } catch (Throwable $e) {
        $result = 'error '.get_class($e).': '.$e->getMessage();
    }

    $redis->rpush("race:{$worker}:results", json_encode(['round' => $data['round'], 'result' => $result]));
}
