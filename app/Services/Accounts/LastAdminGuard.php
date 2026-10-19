<?php

namespace App\Services\Accounts;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * The atomic last-administrator protection (feature 007 FR-028, research R8): a write of an
 * administrator account runs in one transaction that first locks every administrator row in
 * primary-key order (`SELECT … FOR UPDATE`), so that any two such writes are serialised, then
 * applies the change and counts the active administrators again. A change that would leave none
 * while there was one is rolled back and refused with `409 last-active-admin`; a change that does
 * not reduce their number is never refused. Deadlocks are retried (three attempts).
 */
class LastAdminGuard
{
    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     *
     * @throws ProblemException last-active-admin
     */
    public function within(callable $change): mixed
    {
        return DB::transaction(function () use ($change): mixed {
            $before = DB::table('admin')->where('superadmin', 1)->orderBy('username')->lockForUpdate()->get(['username', 'active'])
                ->filter(fn (object $row): bool => (int) $row->active === 1)
                ->count();

            $result = $change();

            $after = DB::table('admin')->where('superadmin', 1)->where('active', 1)->count();

            if ($after === 0 && $before > 0) {
                throw new ProblemException(ProblemType::LastActiveAdmin);
            }

            return $result;
        }, 3);
    }
}
