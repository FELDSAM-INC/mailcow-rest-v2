<?php

/*
|--------------------------------------------------------------------------
| Cache stores (research R6, R14, R17)
|--------------------------------------------------------------------------
|
| Everything lives in mailcow's Redis behind the `restv2:` connection prefix. The default store
| `restv2` holds throttle counters (`restv2:signin:*`, `restv2:passkey:*`) and the legacy
| configuration cache (`restv2:mailcow-vars:*`). The store `sessions` gives the session keys
| `restv2:session:<id>`.
|
*/

return [

    'default' => 'restv2',

    'stores' => [

        'restv2' => [
            'driver' => 'redis',
            'connection' => 'restv2',
            'lock_connection' => 'restv2',
            'prefix' => '',
        ],

        'sessions' => [
            'driver' => 'redis',
            'connection' => 'restv2',
            'lock_connection' => 'restv2',
            'prefix' => 'session:',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

    ],

    'prefix' => '',

    'serializable_classes' => false,

];
