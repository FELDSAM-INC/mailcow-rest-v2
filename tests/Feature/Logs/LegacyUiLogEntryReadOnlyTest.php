<?php

namespace Tests\Feature\Logs;

use App\Models\Mailcow\LegacyUiLogEntry;
use LogicException;
use Tests\Support\Monitoring\LegacyUiLogRows;
use Tests\TestCase;

/**
 * Feature 011 T110 (FR-068): the legacy activity log is read only through its model.
 */
class LegacyUiLogEntryReadOnlyTest extends TestCase
{
    use LegacyUiLogRows;

    public function test_saving_and_deleting_throw(): void
    {
        $id = $this->legacyUiLog('success', 'm', null);
        $entry = LegacyUiLogEntry::query()->findOrFail($id);

        try {
            $entry->delete();
            $this->fail('the entry was deleted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $entry->forceFill(['type' => 'danger'])->save();
    }
}
