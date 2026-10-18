<?php

namespace Tests\Support\Http;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\RoutingEmail;
use App\Rules\SmtpUsername;

/**
 * Form Request of feature 009's RoutingRulesTest: one field per routing rule.
 */
class RoutingRulesProbeRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['bail', 'sometimes', 'string', new SmtpUsername],
            'sender' => ['bail', 'sometimes', 'string', new RoutingEmail],
            'recipient' => ['bail', 'sometimes', 'string', new RoutingEmail],
        ];
    }
}
