<?php

namespace App\Services\MassMail;

use App\Exceptions\ValidationProblemException;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The recipients of a mass mail selection (feature 013 FR-072, FR-073, research R19). The selection
 * starts from every receiving mailbox, the mailboxes Dovecot accepts mail for (`active` 1 or 2) that
 * are not resources (`kind` empty); legacy also includes inactive mailboxes, the spec narrows it.
 * It may be limited to domains of the server (not alias domains) and then include or exclude
 * mailboxes, not both. Every named entry must belong to that set; each failing one is named
 * (`notFound`, `notEligible` with `reason: notReceiving` or `outsideSelectedDomains`,
 * `mutuallyExclusive`). Evaluated on mailcow's current data on every call.
 */
class SelectionResolver
{
    /**
     * @throws ValidationProblemException
     */
    public function validate(Selection $selection): void
    {
        $this->violations($selection)->throwIfAny();
    }

    /**
     * Every failing entry of the selection.
     */
    public function violations(Selection $selection): FieldViolations
    {
        $violations = new FieldViolations;

        if ($selection->include !== [] && $selection->exclude !== []) {
            $violations->add('selection.exclude', 'mutuallyExclusive', ['with' => 'include'], 'Either include or exclude mailboxes, not both.');
        }

        $known = $selection->domains === [] ? [] : DB::table('domain')->whereIn('domain', array_map(fn (string $d): string => DomainName::normalize($d) ?? $d, $selection->domains))->pluck('domain')->map(fn (mixed $d): string => mb_strtolower((string) $d))->all();

        foreach ($selection->domains as $i => $domain) {
            if (! in_array(DomainName::normalize($domain) ?? $domain, $known, true)) {
                $violations->add("selection.domains.{$i}", 'notFound', [], 'Not a domain of this server.');
            }
        }

        foreach (['include' => $selection->include, 'exclude' => $selection->exclude] as $list => $addresses) {
            $this->validateMailboxes($violations, $list, $addresses, $selection->domains);
        }

        return $violations;
    }

    /**
     * The number of recipients of the whole selection.
     */
    public function count(Selection $selection): int
    {
        return $this->query($selection)->count();
    }

    /**
     * The recipients as `MassMailPreviewRecipient`, sorted by address and paged; `q` filters the
     * listed addresses, `meta.recipientCount` counts the whole selection.
     *
     * @return array{data: list<mixed>, meta: array<string, int>}
     */
    public function preview(Selection $selection, ListQuery $query): array
    {
        $rows = $query->applySearch($this->query($selection), ['username']);
        $page = $query->paginate($rows, fn (object $row): array => ['address' => (string) $row->username, 'domain' => (string) $row->domain]);
        $page['meta']['recipientCount'] = $this->count($selection);

        return $page;
    }

    /**
     * The recipient addresses, sorted.
     *
     * @return list<string>
     */
    public function addresses(Selection $selection): array
    {
        return $this->query($selection)->orderBy('username')->pluck('username')->map(fn (mixed $u): string => (string) $u)->all();
    }

    /**
     * The receiving mailboxes of the selection.
     */
    private function query(Selection $selection): Builder
    {
        $query = DB::table('mailbox')->select(['username', 'domain'])->where('kind', '')->whereIn('active', [1, 2]);

        if ($selection->domains !== []) {
            $query->whereIn('domain', array_map(fn (string $d): string => DomainName::normalize($d) ?? $d, $selection->domains));
        }

        if ($selection->include !== []) {
            $query->whereIn('username', $selection->include);
        } elseif ($selection->exclude !== []) {
            $query->whereNotIn('username', $selection->exclude);
        }

        return $query;
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $domains
     */
    private function validateMailboxes(FieldViolations $violations, string $list, array $addresses, array $domains): void
    {
        if ($addresses === []) {
            return;
        }

        $rows = DB::table('mailbox')->whereIn('username', $addresses)->get(['username', 'domain', 'kind', 'active'])
            ->keyBy(fn (object $row): string => mb_strtolower((string) $row->username));
        $domains = array_map(fn (string $d): string => DomainName::normalize($d) ?? $d, $domains);

        foreach ($addresses as $i => $address) {
            $row = $rows->get($address);

            if ($row === null) {
                $violations->add("selection.{$list}.{$i}", 'notFound', [], 'Not a mailbox of this server.');
            } elseif ((string) $row->kind !== '' || ! in_array((int) $row->active, [1, 2], true)) {
                $violations->add("selection.{$list}.{$i}", 'notEligible', ['reason' => 'notReceiving'], 'The mailbox does not receive mail.');
            } elseif ($domains !== [] && ! in_array(mb_strtolower((string) $row->domain), $domains, true)) {
                $violations->add("selection.{$list}.{$i}", 'notEligible', ['reason' => 'outsideSelectedDomains'], 'The mailbox is not in the selected domains.');
            }
        }
    }
}
