<?php

use App\Support\Config\Env;

/*
|--------------------------------------------------------------------------
| Browser sessions (research R6, R7)
|--------------------------------------------------------------------------
|
| Sessions live in mailcow's Redis (`restv2:session:<id>`, cache store `sessions`). The cookie is
| `__Host-restv2_session`: Secure, HttpOnly, SameSite=Strict, Path=/ and no Domain. Its name can
| be changed with SESSION_COOKIE for local development only (browsers drop __Host- cookies on
| plain HTTP). The idle lifetime is replaced at boot by mailcow's SESSION_LIFETIME
| (App\Providers\AppServiceProvider).
|
*/

return [

    'driver' => 'redis',

    'lifetime' => 180,

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => 'restv2',

    'table' => null,

    'store' => 'sessions',

    'lottery' => [2, 100],

    'cookie' => Env::get('SESSION_COOKIE', '__Host-restv2_session'),

    'path' => '/',

    'domain' => null,

    'secure' => true,

    'http_only' => true,

    'same_site' => 'strict',

    'partitioned' => false,

    'serialization' => 'json',

];
