<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Commands\TestReleaseCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Feature 006 T049: the test-only command of the quarantine concurrency test. tests/ is not part
// of the production image, and the command exists only in the testing environment.
if (app()->environment('testing') && class_exists(TestReleaseCommand::class)) {
    Artisan::registerCommand(app(TestReleaseCommand::class));
}
