<?php

namespace Tests\Support\MassMail;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Settings\SettingsTestHelpers;

/**
 * Mailboxes, jobs and requests shared by the mass mail tests of feature 013.
 */
trait MassMailTestHelpers
{
    use SettingsTestHelpers;

    /**
     * Mailboxes inserted in one statement (no password hashing): `count` receiving mailboxes
     * `user001@<domain>` … per domain, the domains created when missing.
     *
     * @param  array<string, int>  $perDomain
     */
    protected function receivingMailboxes(array $perDomain): void
    {
        $rows = [];

        foreach ($perDomain as $domain => $count) {
            $this->mailcow()->ensureDomain($domain);

            for ($i = 1; $i <= $count; $i++) {
                $rows[] = $this->mailboxRow(sprintf('user%03d@%s', $i, $domain));
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('mailbox')->insert($chunk);
        }
    }

    protected function extraMailbox(string $address, int $active = 1, string $kind = ''): void
    {
        $this->mailcow()->ensureDomain(substr((string) strrchr($address, '@'), 1));
        DB::table('mailbox')->insert($this->mailboxRow($address, $active, $kind));
    }

    /**
     * @return array<string, mixed>
     */
    private function mailboxRow(string $address, int $active = 1, string $kind = ''): array
    {
        [$local, $domain] = explode('@', $address, 2);

        return [
            'username' => $address, 'password' => '{SSHA256}x', 'name' => $local, 'quota' => 0, 'local_part' => $local,
            'domain' => $domain, 'attributes' => '{}', 'kind' => $kind, 'authsource' => 'mailcow', 'active' => $active,
        ];
    }

    /**
     * A running job with the given recipients, inserted as the creation inserts it.
     *
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $overrides
     */
    protected function runningJob(array $recipients, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('restv2_mass_mail_jobs')->insert($overrides + [
            'id' => $id,
            'status' => 'running',
            'running_slot' => 1,
            'sender' => 'noreply@mail.example.org',
            'subject' => 'Maintenance',
            'text_body' => "Maintenance tonight.\n",
            'html_body' => null,
            'selection' => '{"domains":[],"include":[],"exclude":[]}',
            'recipient_count' => count($recipients),
            'created_by' => 'admin',
            'created_request_id' => str_repeat('a', 32),
            'created_client_address' => '192.0.2.10',
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        foreach (array_chunk($recipients, 1000) as $chunk) {
            DB::table('restv2_mass_mail_recipients')->insert(array_map(fn (string $address): array => ['job_id' => $id, 'address' => $address], $chunk));
        }

        return $id;
    }

    /**
     * @return array<string, string> address => outcome
     */
    protected function outcomes(string $jobId): array
    {
        return DB::table('restv2_mass_mail_recipients')->where('job_id', $jobId)->orderBy('address')->pluck('outcome', 'address')->map(fn (mixed $o): string => (string) $o)->all();
    }
}
