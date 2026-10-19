<?php

/*
 * Feature 007 T080: a race worker of SsoTokenConcurrencyTest. It boots the application once
 * (APP_ENV=testing, its own database connection), then takes one job per round from the Redis list
 * `restv2:race:<worker>`, waits for the job's start time and consumes the token: worker `api`
 * through SsoTokenService, worker `legacy` with legacy domain_admin_sso('check')'s statements
 * (SELECT, then DELETE with a row count). The outcome goes to `restv2:race:<worker>:results`.
 *
 *   php tests/bin/sso-token-race.php <api|legacy>
 */

use App\Services\Auth\SsoTokenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$worker = (string) ($argv[1] ?? 'api');
$redis = Redis::connection('restv2');
$redis->rpush("race:{$worker}:ready", '1');
$idleSince = microtime(true);

while (true) {
    // Redis reads time out after 5 s: poll in short blocking steps.
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
        usleep(50);
    }

    try {
        if ($worker === 'api') {
            $result = $app->make(SsoTokenService::class)->consume((string) $data['token']) !== null ? 'signedIn' : 'refused';
        } else {
            // Legacy domain_admin_sso('check'), statement by statement.
            $row = DB::selectOne("SELECT `t1`.`username` FROM `da_sso` AS `t1` JOIN `admin` AS `t2` ON `t1`.`username` = `t2`.`username` WHERE `t1`.`token` = ? AND `t1`.`created` > DATE_SUB(NOW(), INTERVAL '30' SECOND) AND `t2`.`active` = 1 AND `t2`.`superadmin` = 0", [(string) $data['token']]);
            $result = $row === null || DB::delete('DELETE FROM `da_sso` WHERE `token` = ?', [(string) $data['token']]) < 1 ? 'refused' : 'signedIn';
        }
    } catch (Throwable $e) {
        $result = 'error '.get_class($e).': '.$e->getMessage();
    }

    $redis->rpush("race:{$worker}:results", json_encode(['round' => $data['round'], 'result' => $result]));
}
