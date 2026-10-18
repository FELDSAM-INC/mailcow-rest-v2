<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\TestCase;

/**
 * Feature 008 T006 (research R20): the runner simulator selects and updates jobs as the runner's
 * SQL does: never-run active jobs are selected, running and inactive ones are not, a finished job
 * waits for its interval, and the restart clears every running flag.
 */
class ImapsyncRunnerSimulatorTest extends TestCase
{
    private ImapsyncRunnerSimulator $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new ImapsyncRunnerSimulator;
        $this->mailcow()->mailboxWithDomain('alice@example.org');
    }

    public function test_a_never_run_active_job_is_selected_and_an_inactive_one_is_not(): void
    {
        $active = $this->mailcow()->syncJob('alice@example.org');
        $inactive = $this->mailcow()->syncJob('alice@example.org', ['user1' => 'other', 'active' => 0]);

        $this->assertContains($active, $this->runner->selectableIds());
        $this->assertNotContains($inactive, $this->runner->selectableIds());
    }

    public function test_a_running_job_is_not_selected_and_a_finished_one_waits_for_its_interval(): void
    {
        $id = $this->mailcow()->syncJob('alice@example.org', ['mins_interval' => 20]);

        $this->runner->startRun($id);
        $row = DB::table('imapsync')->where('id', $id)->first();
        $this->assertSame(1, (int) $row?->is_running);
        $this->assertNull($row?->success);
        $this->assertNotContains($id, $this->runner->selectableIds());

        $this->assertSame(1, $this->runner->finishRun($id, 'Exiting with return value 0', 1, 'EX_OK'));
        $row = DB::table('imapsync')->where('id', $id)->first();
        $this->assertSame(0, (int) $row?->is_running);
        $this->assertSame(1, (int) $row?->success);
        $this->assertSame('EX_OK', $row?->exit_status);
        $this->assertSame('Exiting with return value 0', $row?->returned_text);
        $this->assertNotNull($row?->last_run);
        $this->assertNotContains($id, $this->runner->selectableIds());

        DB::table('imapsync')->where('id', $id)->update(['last_run' => DB::raw('NOW() - INTERVAL 21 MINUTE')]);
        $this->assertContains($id, $this->runner->selectableIds());
    }

    public function test_a_final_write_for_a_deleted_job_affects_nothing_and_a_restart_clears_running_flags(): void
    {
        $gone = $this->mailcow()->syncJob('alice@example.org');
        $stuck = $this->mailcow()->syncJob('alice@example.org', ['user1' => 'other', 'is_running' => 1]);

        $this->runner->startRun($gone);
        DB::table('imapsync')->where('id', $gone)->delete();
        $this->assertSame(0, $this->runner->finishRun($gone, 'log', 0, 'EXIT_AUTHENTICATION_FAILURE_USER1'));

        $this->assertNotContains($stuck, $this->runner->selectableIds());
        $this->runner->restart();
        $this->assertContains($stuck, $this->runner->selectableIds());
    }
}
