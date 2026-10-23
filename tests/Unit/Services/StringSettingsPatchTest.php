<?php

namespace Tests\Unit\Services;

use App\Services\Settings\StringSettingsPatch;
use PHPUnit\Framework\TestCase;

class StringSettingsPatchTest extends TestCase
{
    public function test_omitted_unchanged_and_missing_empty_values_do_not_plan_a_write(): void
    {
        $raw = ['FROM' => null, 'SUBJECT' => 'Stored', 'HTML' => 'private template'];
        $members = ['sender' => 'FROM', 'subject' => 'SUBJECT', 'htmlTemplate' => 'HTML'];

        $this->assertNull(StringSettingsPatch::plan($raw, [], $members, ['htmlTemplate']));
        $this->assertNull(StringSettingsPatch::plan($raw, ['sender' => '', 'subject' => 'Stored'], $members, ['htmlTemplate']));
    }

    public function test_explicit_empty_strings_are_changes_and_template_audit_contains_only_byte_counts(): void
    {
        $oldTemplate = '<p>Private café</p>';
        $newTemplate = '<p>Private résumé</p>';
        $plan = StringSettingsPatch::plan(
            ['SUBJECT' => 'Stored', 'TEXT' => $oldTemplate, 'HTML' => null],
            ['subject' => '', 'textTemplate' => '', 'htmlTemplate' => $newTemplate],
            ['subject' => 'SUBJECT', 'textTemplate' => 'TEXT', 'htmlTemplate' => 'HTML'],
            ['textTemplate', 'htmlTemplate'],
        );

        $this->assertNotNull($plan);
        $this->assertSame([
            'subject' => ['old' => 'Stored', 'new' => ''],
            'textTemplate' => ['changed' => true, 'oldBytes' => strlen($oldTemplate), 'newBytes' => 0],
            'htmlTemplate' => ['changed' => true, 'oldBytes' => 0, 'newBytes' => strlen($newTemplate)],
        ], $plan->audit);
        $this->assertStringNotContainsString('Private', json_encode($plan->audit, JSON_THROW_ON_ERROR));
    }
}
