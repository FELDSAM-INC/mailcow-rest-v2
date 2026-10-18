<?php

namespace Tests\Feature\Mailboxes;

use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T068 (FR-037, US5 AS-4): custom attributes are added, replaced or removed per name and
 * stored where legacy's domain footers read them.
 */
class UpdateMailboxCustomAttributesTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', customAttributes: ['old' => '1']);
        $this->asAdmin();
    }

    public function test_attributes_are_added_and_removed_per_name(): void
    {
        $this->patchMailbox('anna@example.org', ['customAttributes' => ['department' => 'Sales', 'old' => null]])->assertOk()
            ->assertJsonPath('data.customAttributes', ['department' => 'Sales']);

        $stored = (string) $this->mailboxRow('anna@example.org')?->custom_attributes;
        $this->assertSame(['department' => 'Sales'], json_decode($stored, true), 'As footer.php decodes the column.');
        $this->assertSame([
            'customAttributes.department' => ['old' => null, 'new' => 'Sales'],
            'customAttributes.old' => ['old' => '1', 'new' => null],
        ], $this->auditOf('mailboxUpdate')[0]->details['changes']);

        $this->patchMailbox('anna@example.org', ['customAttributes' => ['department' => null]])->assertOk();
        $this->assertSame('{}', $this->mailboxRow('anna@example.org')?->custom_attributes);
    }

    public function test_names_with_whitespace_and_too_many_attributes_are_refused(): void
    {
        $errors = $this->patchMailbox('anna@example.org', ['customAttributes' => ['two words' => 'x']])->assertStatus(422)->json('errors');
        $this->assertSame('invalidFormat', $errors['customAttributes.two words'][0]['code'] ?? null);

        $many = [];
        for ($i = 1; $i <= 100; $i++) {
            $many["a{$i}"] = 'v';
        }

        $this->patchMailbox('anna@example.org', ['customAttributes' => $many])->assertStatus(422)
            ->assertJsonPath('errors.customAttributes.0.code', 'tooMany')
            ->assertJsonPath('errors.customAttributes.0.params.max', 100);
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->withoutRequestValidation();

        $errors = $this->patchMailbox('anna@example.org', ['customAttributes' => ['long' => str_repeat('x', 4097), 'number' => 5]])->assertStatus(422)->json('errors');

        $this->assertSame('tooLong', $errors['customAttributes.long'][0]['code']);
        $this->assertSame('invalidType', $errors['customAttributes.number'][0]['code']);
    }
}
