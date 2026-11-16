<?php

namespace App\Services\MailboxGroups;

use App\Auth\Principal;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Referential validation inside the group transaction; no address can be claimed from another object. */
final class GroupValidator
{
    public function __construct(private readonly GroupScope $scope) {}

    /** @param array<string, mixed> $values */
    public function validate(array $values, ?stdClass $old, Principal $actor): void
    {
        $domain = DB::table('domain')->where('domain', $values['domain'])->lockForUpdate()->first();
        if ($domain === null || ! $this->scope->domain($actor, $values['domain'])) {
            throw new NotFoundHttpException;
        }
        $errors = new FieldViolations;
        if ($values['name'] === '') {
            $errors->add('name', 'required');
        }
        $id = $old?->id;
        $other = fn () => DB::table('mailbox_group')->when($id !== null, fn ($q) => $q->where('id', '<>', $id));
        if ($other()->where('domain', $values['domain'])->where('name', $values['name'])->exists()) {
            $errors->add('name', 'conflictsWith');
        }
        if ($values['idp_ref'] !== null && $other()->where('idp_ref', $values['idp_ref'])->exists()) {
            $errors->add('identityReference', 'conflictsWith');
        }
        $address = $values['address'];
        if ($address !== null && $address !== $old?->address) {
            if (substr(strrchr($address, '@'), 1) !== $values['domain']) {
                $errors->add('address', 'invalidFormat', ['format' => 'email']);
            }
            if (DB::table('alias')->where('address', $address)->exists() || DB::table('mailbox')->where('username', $address)->exists()
                || DB::table('spamalias')->where('address', $address)->exists()) {
                $errors->add('address', 'conflictsWith');
            }
            $count = DB::table('alias')->where('domain', $values['domain'])
                ->whereNotIn('address', fn ($q) => $q->select('username')->from('mailbox')->where('domain', $values['domain']))->count();
            if ($old?->address === null && $count >= (int) $domain->aliases) {
                $errors->add('address', 'tooMany', ['max' => (int) $domain->aliases]);
            }
        }
        if (isset($values['template_id']) && ! DB::table('templates')->where('id', $values['template_id'])->where('type', 'mailbox')->exists()) {
            $errors->add('templateId', 'notFound', ['resource' => 'mailboxTemplate']);
        }
        $errors->throwIfAny();
    }

    /** @param array<string, mixed> $patch */
    public function relations(array $patch, Principal $actor, ?string $address, ?int $id): void
    {
        $errors = new FieldViolations;
        $existing = $id === null ? [] : DB::table('mailbox_group_member')->where('group_id', $id)->pluck('username')->all();
        foreach ($patch['members'] ?? [] as $i => $mailbox) {
            if (! in_array(strtolower($mailbox), $existing, true) && ! $this->scope->mailbox($actor, strtolower($mailbox))) {
                $errors->add('members.'.$i, 'notFound', ['resource' => 'mailbox']);
            }
        }
        foreach ($patch['sendAs'] ?? [] as $i => $sendAs) {
            $sendAs = strtolower(trim($sendAs));
            $domain = substr((string) strrchr($sendAs, '@'), 1);
            $exists = str_starts_with($sendAs, '@')
                ? DB::table('domain')->where('domain', $domain)->exists() || DB::table('alias_domain')->where('alias_domain', $domain)->exists()
                : $sendAs !== $address && DB::table('alias')->where('address', $sendAs)->exists();
            if (! $exists || ! $this->scope->domain($actor, $domain)) {
                $errors->add('sendAs.'.$i, 'notFound', ['resource' => 'address']);
            }
        }
        foreach (['imapGrants', 'sogoGrants'] as $field) {
            $seen = [];
            foreach ($patch[$field] ?? [] as $i => $grant) {
                $owner = strtolower($grant['owner']);
                if (! $this->scope->mailbox($actor, $owner)) {
                    $errors->add($field.'.'.$i.'.owner', 'notFound', ['resource' => 'mailbox']);
                }
                $key = $owner.'|'.($grant['type'] ?? '').'|'.($grant['folder'] ?? '');
                if (isset($seen[$key])) {
                    $errors->add($field.'.'.$i, 'duplicate');
                }
                $seen[$key] = true;
            }
        }
        $errors->throwIfAny();
    }
}
