<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Domains\DomainScope;
use App\Support\Http\ProblemType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who may read and change a domain's quota notification copy recipients (feature 013 FR-001,
 * FR-003, FR-046, FR-048): administrators for every domain and domain administrators for the
 * domains they manage, without a permission flag, as in legacy; mailbox users are forbidden. A
 * domain outside the caller's scope, an unknown domain and an alias domain all answer "not found"
 * (002's DomainScope, evaluated on every request).
 */
class QuotaNotificationRecipientsPolicy
{
    public function __construct(private readonly DomainScope $scope) {}

    /**
     * The normalised domain name.
     *
     * @throws ProblemException `403 forbidden` for mailbox users
     * @throws NotFoundHttpException
     */
    public function resolve(Principal $principal, string $domain): string
    {
        if (! in_array($principal->role, [Role::Admin, Role::DomainAdmin], true)) {
            throw new ProblemException(ProblemType::Forbidden);
        }

        return $this->scope->resolve($principal, $domain) ?? throw new NotFoundHttpException('The domain does not exist.');
    }
}
