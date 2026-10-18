<?php

namespace App\Http\Resources\Api\V2;

use App\Http\Requests\Api\V2\Oauth2ClientBulkActionRequest;
use App\Services\Config\MailcowConfig;
use App\Services\Oauth2\Oauth2ClientService;
use App\Support\Http\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Oauth2ClientCapabilities` (FR-049, FR-064, research R20): the unchanged legacy endpoint
 * paths, the scope, the grant types legacy's server registers (prerequisites.inc.php), the token
 * lifetimes in effect (legacy `$ACCESS_TOKEN_LIFETIME` and `$REFRESH_TOKEN_LIFETIME`, server
 * settings first), the redirect URI limit, the bulk limit and the page sizes.
 *
 * @property array<string, mixed> $resource
 */
class Oauth2ClientCapabilitiesResource extends JsonResource
{
    public static function build(): self
    {
        $config = app(MailcowConfig::class);

        return new self([
            'endpoints' => ['authorization' => '/oauth/authorize', 'token' => '/oauth/token', 'profile' => '/oauth/profile'],
            'scope' => 'profile',
            'grantTypes' => ['authorization_code', 'refresh_token', 'password'],
            'accessTokenLifetimeSeconds' => $config->accessTokenLifetime(),
            'refreshTokenLifetimeSeconds' => $config->refreshTokenLifetime(),
            'redirectUrisMaxLength' => Oauth2ClientService::REDIRECT_URIS_MAX_LENGTH,
            'bulkLimit' => Oauth2ClientBulkActionRequest::LIMIT,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
