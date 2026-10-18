<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Identity\IdentityService;
use App\Support\Http\ProblemType;

/**
 * Who may change which domain attribute (FR-032, research D11 step 6): administrators every
 * editable attribute; domain administrators the Global Address List flag and the tags, and the
 * description only with the `domainDesc` permission flag. A request that would change any other
 * attribute is refused as a whole with `403 attribute-not-permitted` naming every such attribute;
 * attributes sent with their current value are always accepted (legacy silently ignores them).
 */
class DomainAttributeGuard
{
    public function __construct(private readonly IdentityService $identity) {}

    /**
     * @param  list<string>  $changed  editable attributes the request changes
     */
    public function authorize(Principal $principal, array $changed): void
    {
        if ($principal->role === Role::Admin) {
            return;
        }

        $allowed = ['globalAddressList', 'tags'];

        if ($principal->role === Role::DomainAdmin && ($this->identity->permissions($principal)['domainDesc'] ?? false)) {
            $allowed[] = 'description';
        }

        $refused = array_values(array_diff($changed, $allowed));
        sort($refused);

        if ($refused !== []) {
            throw new ProblemException(ProblemType::AttributeNotPermitted, 'Your role or permission flags do not allow changing: '.implode(', ', $refused).'.', ['attributes' => $refused]);
        }
    }
}
