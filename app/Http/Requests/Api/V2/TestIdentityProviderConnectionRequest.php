<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `testIdentityProviderConnection` (`IdentityProviderInput`): the rules of a whole save,
 * except that mapped templates need not exist (FR-019). The secret rule is the save's.
 */
class TestIdentityProviderConnectionRequest extends SaveIdentityProviderRequest
{
    protected bool $checkTemplates = false;

    protected string $ability = 'test';
}
