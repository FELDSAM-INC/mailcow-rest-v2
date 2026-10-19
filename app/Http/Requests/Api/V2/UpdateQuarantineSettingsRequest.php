<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;
use App\Rules\CodedRule;
use App\Rules\EmailAddress;
use App\Rules\NotificationTemplate;
use App\Rules\QueryBoolean;
use App\Rules\StrictInteger;
use App\Rules\WholeMebibytes;
use App\Services\Quarantine\QuarantineSettingsService;
use App\Support\Domain\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * Body of `updateQuarantineSettings` (`QuarantineSettingsPatch`, feature 006 FR-029 to FR-032,
 * FR-070): a JSON Merge Patch of at least one member, each checked by data-model.md "Quarantine
 * settings"; every invalid member is reported together and nothing is silently corrected. Excluded
 * domains are normalised as feature 002 does and must name an existing domain or alias domain, or
 * be an entry that is already stored. A non-empty template is checked by the notifier's engine.
 * Administrators only (`forbidden`, before validation). `validateOnly=true` applies every rule.
 */
class UpdateQuarantineSettingsRequest extends ApiFormRequest
{
    public const MEMBERS = [
        'retentionPerMailbox', 'maxMessageSize', 'notificationScoreLimit', 'maxAgeDays', 'excludedDomains', 'releaseFormat',
        'notificationSender', 'notificationSubject', 'copyRecipient', 'redirectRecipient', 'notificationTemplate',
    ];

    public const MAX_SIZE = 2147483647 * 1048576;

    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        app(QuarantinePolicy::class)->authorize(RequestContext::requirePrincipal($this), QuarantinePolicy::CHANGE_SETTINGS);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $address = ['bail', 'nullable', 'string', 'max:255', new EmailAddress(allowEmpty: true)];

        return [
            'validateOnly' => ['bail', new QueryBoolean],
            'retentionPerMailbox' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:0,2147483647'],
            'maxMessageSize' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:0,'.self::MAX_SIZE, new WholeMebibytes],
            'notificationScoreLimit' => ['bail', 'nullable', 'numeric'],
            'maxAgeDays' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:1,2147483647'],
            'excludedDomains' => ['bail', 'sometimes', 'array', 'list', 'max:10000'],
            'excludedDomains.*' => ['bail', 'string', 'max:255', ...$this->excludedDomainRules()],
            'releaseFormat' => ['bail', 'sometimes', 'string', 'in:original,attachment'],
            'notificationSender' => $address,
            'notificationSubject' => ['bail', 'nullable', 'string', 'max:255'],
            'copyRecipient' => $address,
            'redirectRecipient' => $address,
            'notificationTemplate' => ['bail', 'nullable', new NotificationTemplate],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $validateOnly = $this->query('validateOnly');

        return $this->json()->all() + ($validateOnly === null ? [] : ['validateOnly' => $validateOnly]);
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, self::MEMBERS, true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function validateOnly(): bool
    {
        return in_array($this->query('validateOnly'), ['true', '1'], true);
    }

    /**
     * The sent members, excluded domains normalised and deduplicated.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = array_intersect_key($this->json()->all(), array_flip(self::MEMBERS));

        if (isset($patch['excludedDomains']) && is_array($patch['excludedDomains'])) {
            $patch['excludedDomains'] = array_values(array_unique(array_map(fn (mixed $d): string => DomainName::normalize((string) $d) ?? mb_strtolower(trim((string) $d)), $patch['excludedDomains'])));
        }

        return $patch;
    }

    /**
     * One precomputed rule per excluded domain (by index): `invalidFormat` (`domainName`) or
     * `notFound` unless it is an existing domain or alias domain or an entry already stored.
     *
     * @return list<mixed>
     */
    private function excludedDomainRules(): array
    {
        $domains = $this->json('excludedDomains');

        if (! is_array($domains) || $domains === []) {
            return [];
        }

        $normalised = [];

        foreach ($domains as $raw) {
            if (is_string($raw)) {
                $normalised[] = DomainName::normalize($raw);
            }
        }

        $candidates = array_values(array_filter($normalised, 'is_string'));
        $existing = $candidates === [] ? [] : DB::table('domain')->whereIn('domain', $candidates)->pluck('domain')
            ->merge(DB::table('alias_domain')->whereIn('alias_domain', $candidates)->pluck('alias_domain'))
            ->map(fn (mixed $d): string => mb_strtolower((string) $d))->all();
        $stored = array_map('mb_strtolower', app(QuarantineSettingsService::class)->storedExcludedDomains());

        return [new class($existing, $stored) extends CodedRule
        {
            /**
             * @param  list<string>  $existing
             * @param  list<string>  $stored
             */
            public function __construct(private readonly array $existing, private readonly array $stored) {}

            protected function check(mixed $value): ?array
            {
                $name = is_string($value) ? DomainName::normalize($value) : null;

                if ($name === null || (! DomainName::isValid($name) && ! in_array($name, $this->stored, true))) {
                    return $this->violation('invalidFormat', ['format' => 'domainName']);
                }

                return in_array($name, $this->existing, true) || in_array($name, $this->stored, true)
                    ? null
                    : $this->violation('notFound', [], 'Neither a domain nor an alias domain of this server.');
            }
        }];
    }
}
