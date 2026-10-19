<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Rules\QueryBoolean;
use App\Services\Accounts\SecondFactorStatus;
use App\Support\Http\ListQuery;

/**
 * Query of the account lists of feature 007 (FR-010 to FR-012): the shared list parameters with the
 * role's sorts (default `username` ascending), `active`, `forcePasswordChange` and
 * `forceSecondFactorSetup` (`true`/`false`) and `secondFactorStatus`.
 */
trait AccountListRules
{
    private ?ListQuery $listQuery = null;

    /**
     * @return array<string, list<mixed>>
     */
    protected function accountListRules(): array
    {
        return [
            'active' => ['bail', new QueryBoolean],
            'forcePasswordChange' => ['bail', new QueryBoolean],
            'forceSecondFactorSetup' => ['bail', new QueryBoolean],
            'secondFactorStatus' => ['bail', 'string', 'in:'.implode(',', array_map(fn (SecondFactorStatus $s): string => $s->value, SecondFactorStatus::cases()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    abstract protected function sorts(): array;

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, $this->sorts(), 'username');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, $this->sorts(), 'username');
    }

    /**
     * @return array{active: bool|null, secondFactorStatus: string|null, forcePasswordChange: bool|null, forceSecondFactorSetup: bool|null}
     */
    protected function accountFilters(): array
    {
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $status = $this->query('secondFactorStatus');

        return [
            'active' => $bool('active'),
            'secondFactorStatus' => is_string($status) ? $status : null,
            'forcePasswordChange' => $bool('forcePasswordChange'),
            'forceSecondFactorSetup' => $bool('forceSecondFactorSetup'),
        ];
    }
}
