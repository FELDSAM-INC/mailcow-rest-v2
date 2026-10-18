<?php

namespace Tests\Unit\Services;

use App\Services\AliasDomains\AliasDomainVersion;
use App\Services\Aliases\AliasVersion;
use App\Services\Resources\ResourceVersion;
use App\Services\SenderSettings\SenderSettingsReader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 004 T008 (FR-060, research R18): version tags change with every stored setting they
 * cover and with nothing else; they never expose a plain hash of a stored value.
 */
class AliasVersionTagTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function alias(): array
    {
        return [
            'id' => 7, 'address' => 'sales@example.org', 'goto' => 'anna@example.org', 'domain' => 'example.org',
            'active' => 1, 'sender_allowed' => 1, 'sogo_visible' => 1, 'internal' => 0,
            'public_comment' => null, 'private_comment' => 'x', 'created' => '2026-10-01 10:00:00', 'modified' => null,
        ];
    }

    public function test_the_alias_tag_covers_exactly_the_stored_settings(): void
    {
        $version = new AliasVersion;
        $base = self::alias();
        $tag = $version->forRow((object) $base);

        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]{43}"$/', $tag);
        $this->assertSame($tag, $version->forRow((object) (['modified' => '2026-10-02 10:00:00', 'created' => '2026-01-01 00:00:00'] + $base)));

        foreach (['id' => 8, 'address' => 'Sales@example.org', 'goto' => 'bob@example.org', 'domain' => 'example.net', 'active' => 0,
            'sender_allowed' => 0, 'sogo_visible' => 0, 'internal' => 1, 'public_comment' => '', 'private_comment' => 'y'] as $column => $value) {
            $this->assertNotSame($tag, $version->forRow((object) ([$column => $value] + $base)), "{$column} is not covered.");
        }
    }

    public function test_the_alias_domain_tag_ignores_counts_relay_state_and_rate_limit(): void
    {
        $version = new AliasDomainVersion;
        $base = ['alias_domain' => 'example.net', 'target_domain' => 'example.org', 'active' => 1, 'created' => '2026-10-01 10:00:00', 'modified' => null, 'alias_count' => 3, 'target_backupmx' => 0];
        $tag = $version->forRow((object) $base);

        $this->assertSame($tag, $version->forRow((object) (['alias_count' => 9, 'target_backupmx' => 1, 'modified' => '2026-10-03 00:00:00'] + $base)));

        foreach (['alias_domain' => 'example.com', 'target_domain' => 'example.com', 'active' => 0] as $column => $value) {
            $this->assertNotSame($tag, $version->forRow((object) ([$column => $value] + $base)), "{$column} is not covered.");
        }
    }

    public function test_the_resource_tag_covers_its_stored_settings(): void
    {
        $version = new ResourceVersion;
        $base = ['username' => 'Room1@example.org', 'name' => 'Room 1', 'kind' => 'location', 'multiple_bookings' => -1, 'active' => 1, 'domain' => 'example.org', 'modified' => null];
        $tag = $version->forRow((object) $base);

        $this->assertSame($tag, $version->forRow((object) (['modified' => '2026-10-03 00:00:00'] + $base)));

        foreach (['username' => 'Room2@example.org', 'name' => 'Room 2', 'kind' => 'thing', 'multiple_bookings' => 2, 'active' => 0, 'domain' => 'example.net'] as $column => $value) {
            $this->assertNotSame($tag, $version->forRow((object) ([$column => $value] + $base)), "{$column} is not covered.");
        }
    }

    public function test_the_sender_settings_tag_covers_the_stored_entries_only(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->legacyMailbox('anna@example.org');
        $reader = $this->app->make(SenderSettingsReader::class);
        $empty = $reader->etagFor('anna@example.org');

        $f->senderAcl('anna@example.org', 'info@example.org');
        $f->senderAcl('anna@example.org', 'news@mailer.example', 1);
        $tag = $reader->etagFor('anna@example.org');
        $this->assertNotSame($empty, $tag);

        // Row order and IDs do not matter.
        DB::table('sender_acl')->delete();
        $f->senderAcl('anna@example.org', 'news@mailer.example', 1);
        $f->senderAcl('anna@example.org', 'info@example.org');
        $this->assertSame($tag, $reader->etagFor('anna@example.org'));

        // An alias delivering to the mailbox is not covered; another mailbox's entries neither.
        $f->alias('sales@example.org', 'anna@example.org');
        $f->senderAcl('bob@example.org', '*');
        $this->assertSame($tag, $reader->etagFor('anna@example.org'));

        // The external flag of an entry is covered.
        DB::table('sender_acl')->where('send_as', 'info@example.org')->update(['external' => 1]);
        $this->assertNotSame($tag, $reader->etagFor('anna@example.org'));
        $this->assertNotSame(hash('sha256', 'info@example.org'), trim($tag, '"'));
    }
}
