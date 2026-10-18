<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The domain-wide footer (`domain_wide_footer`, FR-051–FR-055, research D21), stored where legacy
 * stores it, so that mailcow's mail filter (footer.php) applies it unchanged. A replacement locks
 * the domain row, checks the footer's version, validates every exclusion (the address of a mailbox
 * or alias in the domain or one of its alias domains, or one of its alias domain names; stored
 * trimmed, lower-cased, with an ASCII domain part, duplicates once) and the 1 MiB text limit, then
 * deletes and inserts the row as legacy does, and audits the texts only as sizes.
 */
class DomainFooterService
{
    public const MAX_BYTES = 1048576;

    public function __construct(
        private readonly DomainVersion $version,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The contract's `DomainFooter`.
     *
     * @return array<string, mixed>
     */
    public function read(string $domain): array
    {
        return $this->present($domain, DB::table('domain_wide_footer')->where('domain', $domain)->first());
    }

    /**
     * @param  array<string, mixed>  $input  `DomainFooterReplace`
     * @return array<string, mixed>
     */
    public function replace(Principal $principal, string $domain, array $input, ?string $ifMatch): array
    {
        return DB::transaction(function () use ($principal, $domain, $input, $ifMatch): array {
            DB::table('domain')->where('domain', $domain)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The domain does not exist.');
            $current = DB::table('domain_wide_footer')->where('domain', $domain)->first();

            if (ETag::preconditionFails($ifMatch, $this->version->forFooter($domain, $current))) {
                throw new PreconditionFailedHttpException('The footer was changed since it was read.');
            }

            $violations = new FieldViolations;
            $html = (string) $input['html'];
            $plain = (string) $input['plain'];

            foreach (['html' => $html, 'plain' => $plain] as $field => $text) {
                if (strlen($text) > self::MAX_BYTES) {
                    $violations->add($field, 'tooLong', ['max' => self::MAX_BYTES, 'unit' => 'bytes']);
                }
            }

            $aliasDomains = DB::table('alias_domain')->where('target_domain', $domain)->pluck('alias_domain')->map(fn (mixed $a): string => (string) $a)->all();
            $addresses = $this->addresses((array) ($input['excludedAddresses'] ?? []), [$domain, ...$aliasDomains], $violations);
            $excludedAliasDomains = $this->aliasDomains((array) ($input['excludedAliasDomains'] ?? []), $aliasDomains, $violations);
            $violations->throwIfAny();

            $before = $this->present($domain, $current);
            DB::table('domain_wide_footer')->where('domain', $domain)->delete();
            DB::table('domain_wide_footer')->insert([
                'domain' => $domain,
                'html' => $html,
                'plain' => $plain,
                'mbox_exclude' => json_encode($addresses),
                'alias_domain_exclude' => json_encode($excludedAliasDomains),
                'skip_replies' => (int) (bool) $input['skipReplies'],
            ]);
            $after = $this->read($domain);

            $changes = [
                'html' => ['changed' => $before['html'] !== $html, 'oldSize' => strlen($before['html']), 'newSize' => strlen($html)],
                'plain' => ['changed' => $before['plain'] !== $plain, 'oldSize' => strlen($before['plain']), 'newSize' => strlen($plain)],
            ];

            foreach (['skipReplies', 'excludedAddresses', 'excludedAliasDomains'] as $member) {
                if ($before[$member] !== $after[$member]) {
                    $changes[$member] = ['old' => $before[$member], 'new' => $after[$member]];
                }
            }

            $this->audit->record(AuditEventType::DomainFooterUpdate, true, $principal, ['type' => 'domainFooter', 'id' => $domain, 'name' => DomainName::toUnicode($domain)], null, null, ['changes' => $changes]);

            return $after;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $domain, ?object $row): array
    {
        $list = function (mixed $json): array {
            $decoded = is_string($json) ? json_decode($json, true) : null;

            return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
        };

        return [
            'domain' => $domain,
            'html' => (string) ($row->html ?? ''),
            'plain' => (string) ($row->plain ?? ''),
            'skipReplies' => (bool) ($row->skip_replies ?? false),
            'excludedAddresses' => $list($row->mbox_exclude ?? null),
            'excludedAliasDomains' => $list($row->alias_domain_exclude ?? null),
            'etag' => $this->version->forFooter($domain, $row),
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @param  list<string>  $domains  the domain and its alias domains
     * @return list<string>
     */
    private function addresses(array $entries, array $domains, FieldViolations $violations): array
    {
        $result = [];

        foreach (array_values($entries) as $index => $entry) {
            $address = $this->normaliseAddress((string) (is_scalar($entry) ? $entry : ''));

            if ($address === null) {
                $violations->add("excludedAddresses.{$index}", 'invalidFormat', ['format' => 'email']);

                continue;
            }

            $domainPart = substr((string) strrchr($address, '@'), 1);
            $exists = DB::table('mailbox')->where('username', $address)->exists() || DB::table('alias')->where('address', $address)->exists();

            if (! in_array($domainPart, $domains, true) || ! $exists) {
                $violations->add("excludedAddresses.{$index}", 'notFound', [], 'Not a mailbox or alias address of this domain or its alias domains.');

                continue;
            }

            if (! in_array($address, $result, true)) {
                $result[] = $address;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $entries
     * @param  list<string>  $aliasDomains
     * @return list<string>
     */
    private function aliasDomains(array $entries, array $aliasDomains, FieldViolations $violations): array
    {
        $result = [];

        foreach (array_values($entries) as $index => $entry) {
            $name = is_string($entry) ? DomainName::normalize($entry) : null;

            if ($name === null || ! in_array($name, $aliasDomains, true)) {
                $violations->add("excludedAliasDomains.{$index}", 'notFound', [], 'Not an alias domain of this domain.');

                continue;
            }

            if (! in_array($name, $result, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    private function normaliseAddress(string $entry): ?string
    {
        $entry = mb_strtolower(trim($entry));
        $at = strrpos($entry, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $domain = DomainName::normalizeValid(substr($entry, $at + 1));
        $address = $domain === null ? null : substr($entry, 0, $at).'@'.$domain;

        return $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }
}
