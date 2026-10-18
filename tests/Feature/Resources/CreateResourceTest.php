<?php

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T072 (FR-048–FR-050, US7): createResource.
 */
class CreateResourceTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['mailboxes' => 1]);
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
    }

    public function test_a_location_is_created_as_legacy_stores_it(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v2/resources', ['description' => 'Meeting Room 1', 'domain' => 'Example.ORG', 'kind' => 'location'], $this->csrf())->assertCreated();

        $response->assertHeader('Location', '/api/v2/resources/MeetingRoom1@example.org');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $response->assertJsonPath('data.address', 'MeetingRoom1@example.org')
            ->assertJsonPath('data.bookingRule', 'unlimited')->assertJsonPath('data.bookingLimit', null)
            ->assertJsonPath('data.active', true)->assertJsonPath('data.kind', 'location');
        $row = $this->mailboxRow('MeetingRoom1@example.org');
        $this->assertSame(['RESOURCE', 'Meeting Room 1', 0, 'MeetingRoom1', 'example.org', 'location', -1, 1], [$row?->password, $row?->name, (int) $row?->quota, $row?->local_part, $row?->domain, $row?->kind, (int) $row?->multiple_bookings, (int) $row?->active]);
        $this->assertSame(0, DB::table('user_acl')->where('username', 'MeetingRoom1@example.org')->count());
        $view = $this->viewRow('MeetingRoom1@example.org');
        $this->assertSame(['location', -1], [$view?->kind, (int) $view?->multiple_bookings]);
        $this->assertSame(1, $this->memcached()->flushCount());

        $record = $this->auditOf('resourceCreation')[0];
        $this->assertSame(['resource', 'MeetingRoom1@example.org', 'Meeting Room 1'], [$record->target_type, $record->target_id, $record->target_name]);
        $this->assertSame('done', $record->details['groupwareUpdate']);
        $this->assertSame('unlimited', $record->details['values']['bookingRule']);
    }

    public function test_the_derived_address_must_be_free_and_resources_ignore_the_mailbox_limit(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v2/resources', ['description' => 'Meeting Room 1', 'domain' => 'example.org', 'kind' => 'location', 'bookingRule' => 'limited', 'bookingLimit' => 2], $this->csrf())->assertCreated()
            ->assertJsonPath('data.bookingLimit', 2);

        $this->postJson('/api/v2/resources', ['description' => 'Meeting-Room 1', 'domain' => 'example.org', 'kind' => 'thing'], $this->csrf())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/address-in-use')
            ->assertJsonPath('holder', 'resource');
        $this->postJson('/api/v2/resources', ['description' => 'anna', 'domain' => 'example.org', 'kind' => 'group'], $this->csrf())->assertStatus(409)
            ->assertJsonPath('holder', 'mailbox');

        $this->postJson('/api/v2/resources', ['description' => 'Raum Süd', 'domain' => 'example.org', 'kind' => 'group', 'bookingRule' => 'alwaysFree', 'active' => false], $this->csrf())->assertCreated()
            ->assertJsonPath('data.address', 'RaumSd@example.org')->assertJsonPath('data.description', 'Raum Süd')->assertJsonPath('data.bookingRule', 'alwaysFree');
        $this->assertNull($this->viewRow('RaumSd@example.org'), 'An inactive resource has no groupware entry.');
        $this->assertSame(1, DB::table('mailbox')->where('domain', 'example.org')->where('kind', '')->count());
    }

    public function test_invalid_requests(): void
    {
        $this->asDomainAdmin(['example.org']);
        $cases = [
            [['description' => '!!!', 'domain' => 'example.org', 'kind' => 'location'], 'description', 'invalidFormat', ['format' => 'resourceDescription']],
            [['description' => '   ', 'domain' => 'example.org', 'kind' => 'location'], 'description', 'required', []],
            [['description' => str_repeat('a', 65), 'domain' => 'example.org', 'kind' => 'location'], 'description', 'invalidFormat', ['format' => 'resourceDescription']],
            [['description' => 'Room', 'domain' => 'example.net', 'kind' => 'location'], 'domain', 'notEligible', ['kind' => 'aliasDomain']],
            [['description' => 'Room', 'domain' => 'foreign.example', 'kind' => 'location'], 'domain', 'notFound', ['resource' => 'domain']],
            [['description' => 'Room', 'domain' => 'unknown.example', 'kind' => 'location'], 'domain', 'notFound', ['resource' => 'domain']],
            [['description' => 'Room', 'domain' => 'example.org', 'kind' => 'location', 'bookingRule' => 'limited'], 'bookingLimit', 'required', []],
            [['description' => 'Room', 'domain' => 'example.org', 'kind' => 'location', 'bookingRule' => 'unlimited', 'bookingLimit' => 3], 'bookingLimit', 'conflictsWith', ['field' => 'bookingRule']],
        ];

        foreach ($cases as [$body, $field, $code, $params]) {
            $errors = $this->postJson('/api/v2/resources', $body, $this->csrf())->assertStatus(422)->json('errors');
            $this->assertSame($code, $errors[$field][0]['code'], json_encode($body));
            $this->assertEquals($params, $errors[$field][0]['params']);
        }

        $this->assertSame('validationFailed', $this->auditOf('resourceCreation')[0]->failure_reason);
        $this->assertSame(0, DB::table('mailbox')->where('kind', '<>', '')->count());
    }

    public function test_an_invalid_description_length_and_booking_limit(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->postJson('/api/v2/resources', ['description' => str_repeat('a', 256), 'domain' => 'example.org', 'kind' => 'location'], $this->csrf())->assertStatus(422)
            ->assertJsonPath('errors.description.0.code', 'tooLong')->assertJsonPath('errors.description.0.params.max', 255);
        $this->postJson('/api/v2/resources', ['description' => 'Room', 'domain' => 'example.org', 'kind' => 'location', 'bookingRule' => 'limited', 'bookingLimit' => 0], $this->csrf())->assertStatus(422)
            ->assertJsonPath('errors.bookingLimit.0.code', 'outOfRange');
    }
}
