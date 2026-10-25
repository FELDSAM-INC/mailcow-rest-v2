<?php

use App\Models\ApiKey;
use Database\Factories\MailcowRows;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Security fixtures require the disposable local environment.');
}
$rows = new MailcowRows;
$rows->domain('foreign.example');
$rows->mailbox('foreign@foreign.example', '__PASSWORD__');
$rows->admin('zapadmin', '__PASSWORD__');
$rows->domainAdmin('zapreseller', ['example.org'], password: '__PASSWORD__');
$rows->mailbox('zapuser@example.org', '__PASSWORD__');
$keys = [];
foreach (['readonly' => null, 'revoked' => now()] as $label => $revoked) {
    $secret = 'mcv2_'.Str::random(40);
    ApiKey::create([
        'name' => 'Disposable security '.$label,
        'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
        'owner_username' => 'zapadmin',
        'owner_created_at' => DB::table('admin')->where('username', 'zapadmin')->value('created'),
        'access_level' => 'ro',
        'allowed_networks' => [],
        'created_by' => 'zapadmin',
        'revoked_at' => $revoked,
    ]);
    $keys[$label] = $secret;
}
echo json_encode($keys, JSON_THROW_ON_ERROR);
