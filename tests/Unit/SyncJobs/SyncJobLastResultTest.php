<?php

namespace Tests\Unit\SyncJobs;

use App\Services\SyncJobs\SyncJobLastResult;
use PHPUnit\Framework\TestCase;

/**
 * Feature 008 T008 (research R3): `imapsync.success` 1 is `succeeded`, 0 `failed`, NULL (never
 * run, running, or rescheduled) `unknown`.
 */
class SyncJobLastResultTest extends TestCase
{
    public function test_the_stored_success_maps_to_the_last_result(): void
    {
        $this->assertSame(SyncJobLastResult::Succeeded, SyncJobLastResult::fromStored(1));
        $this->assertSame(SyncJobLastResult::Failed, SyncJobLastResult::fromStored(0));
        $this->assertSame(SyncJobLastResult::Unknown, SyncJobLastResult::fromStored(null));
        $this->assertSame('succeeded', SyncJobLastResult::fromStored(1)->value);
        $this->assertSame('failed', SyncJobLastResult::fromStored(0)->value);
        $this->assertSame('unknown', SyncJobLastResult::fromStored(null)->value);
    }
}
