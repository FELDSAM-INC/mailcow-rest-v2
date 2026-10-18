<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Alias;
use App\Services\Mailboxes\MailboxScope;
use App\Services\SenderSettings\PermissionCandidateQuery;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listSenderPermissionCandidates` (feature 004 FR-056): the required `mailbox` (a mailbox,
 * not a resource, in the caller's scope; otherwise `notFound` on `mailbox`, the same for a mailbox
 * that does not exist), the repeatable `type`, and the shared list parameters (`sort` of
 * `MailboxSenderPermissionCandidateSort`, default `type` ascending; `q`).
 */
class ListSenderPermissionCandidatesRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    private ?string $mailbox = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Alias::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['type']);
        $types = $this->repeatedQuery('type');

        return $types === [] ? $data : $data + ['type' => $types];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mailbox' => ['bail', 'required', 'string', 'min:3', 'max:255'],
            'type' => ['bail', 'array', 'max:3'],
            'type.*' => ['bail', 'string', 'in:address,domain,anyAddress'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('mailbox')) {
                return;
            }

            $this->mailbox = app(MailboxScope::class)->resolve(RequestContext::requirePrincipal($this), (string) $this->query('mailbox'));

            if ($this->mailbox === null) {
                $violations = new FieldViolations;
                $violations->add('mailbox', 'notFound', ['resource' => 'mailbox'], 'The mailbox does not exist.');
                $violations->throwIfAny();
            }
        });
    }

    public function mailbox(): string
    {
        return (string) $this->mailbox;
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, PermissionCandidateQuery::SORTS, 'type');
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_values(array_unique($this->repeatedQuery('type')));
    }
}
