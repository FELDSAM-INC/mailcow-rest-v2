<?php

namespace App\Services\SyncSources;

use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SyncSources\OAuthFailure;
use App\Clients\SyncSources\OAuthHttpClient;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class SourceValidator
{
    public function __construct(private readonly SourceScope $scope, private readonly OAuthHttpClient $http) {}

    /** @param array<string, mixed> $values
     * @param  array{domains: list<string>, users: list<string>}  $targets
     * @param  array<string, mixed>  $patch
     */
    public function validate(array $values, array $targets, ?object $old, array $patch, Principal $actor): void
    {
        $errors = new FieldViolations;
        if ($values['auth_type'] === 'XOAUTH2' && $actor->role === Role::MailboxUser) {
            throw new AccessDeniedHttpException;
        }
        if ($values['scope'] === 'all' && $actor->role !== Role::Admin) {
            throw new AccessDeniedHttpException;
        }
        $private = $actor->role === Role::MailboxUser || ($values['auth_type'] === 'XOAUTH2' && $values['oauth_flow'] === 'client_credentials');
        if ($private && ($values['scope'] !== 'user' || $targets['domains'] !== [] || ($actor->role === Role::MailboxUser ? array_diff($targets['users'], [$actor->username]) !== [] : $targets['users'] !== []))) {
            $errors->add('scope', 'notEligible', [], 'Mailbox-user and client-credentials sources must be private.');
        }
        // Validate only changed targets: editing metadata must not silently remove existing targets.
        foreach (['domains', 'users'] as $key) {
            if ($old !== null && ! array_key_exists($key, $patch) && ! array_key_exists('scope', $patch)) {
                continue;
            }
            foreach ($targets[$key] as $i => $target) {
                if (! ($key === 'domains' ? $this->scope->domain($actor, $target) : $this->scope->mailbox($actor, $target))) {
                    $errors->add($key.'.'.$i, 'notFound');
                }
            }
        }
        if ($values['scope'] === 'domain' && $targets['domains'] === []) {
            $errors->add('domains', 'required');
        }
        if ($values['auth_type'] === 'XOAUTH2') {
            foreach (['oauth_token_endpoint' => 'tokenEndpoint', 'oauth_client_id' => 'clientId', 'oauth_client_secret' => 'clientSecret', 'oauth_scope' => 'scope'] as $col => $field) {
                if ((string) $values[$col] === '') {
                    $errors->add('oauth.'.$field, 'required');
                }
            }
            $endpoints = ['oauth_token_endpoint' => 'tokenEndpoint'];
            if ($values['oauth_flow'] === 'authorization_code') {
                $endpoints['oauth_authorize_endpoint'] = 'authorizationEndpoint';
            }
            if ((string) $values['oauth_userinfo_endpoint'] !== '') {
                $endpoints['oauth_userinfo_endpoint'] = 'userInfoEndpoint';
            }
            foreach ($endpoints as $column => $field) {
                try {
                    $this->http->pin((string) $values[$column], self::publicOnly($values['created_by']));
                } catch (OAuthFailure) {
                    $errors->add('oauth.'.$field, 'invalidFormat', ['format' => 'httpsPublicEndpoint']);
                }
            }
            if ($old !== null && (string) $old->oauth_client_secret !== '' && empty($patch['oauth']['clientSecret'])) {
                foreach (['oauth_token_endpoint', 'oauth_client_id'] as $column) {
                    if ($values[$column] !== $old->$column) {
                        $errors->add('oauth.clientSecret', 'secretRequired', ['reason' => 'addressChanged']);
                    }
                }
            }
        }
        $errors->throwIfAny();
    }

    public static function publicOnly(string $creator): bool
    {
        return ! DB::table('admin')->where('username', $creator)->where('superadmin', 1)->where('active', 1)->exists();
    }
}
