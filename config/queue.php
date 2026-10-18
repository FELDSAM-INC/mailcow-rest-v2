<?php

/*
|--------------------------------------------------------------------------
| Queues
|--------------------------------------------------------------------------
|
| Feature 001 queues nothing. Work runs synchronously within the request, and no queue table
| exists in mailcow's database.
|
*/

return [

    'default' => 'sync',

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
    ],

    'batching' => [
        'database' => 'mysql',
        'table' => 'restv2_job_batches',
    ],

    'failed' => [
        'driver' => 'null',
    ],

];
