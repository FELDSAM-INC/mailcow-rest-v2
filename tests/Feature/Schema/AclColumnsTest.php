<?php

namespace Tests\Feature\Schema;

use App\Auth\PermissionFlag;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T023 (R20): the permission flags are a fixed list in the contract. When an upstream release adds
 * a column to user_acl or da_acl, this test fails, so that the contract is updated deliberately.
 */
class AclColumnsTest extends TestCase
{
    public function test_user_acl_columns_match_the_permission_flags(): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing('user_acl'), ['username']));
        $flags = array_values(array_filter(array_map(fn (PermissionFlag $f): ?string => $f->userAclColumn(), PermissionFlag::cases())));

        $this->assertEqualsCanonicalizing($columns, $flags);
    }

    public function test_da_acl_columns_match_the_permission_flags(): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing('da_acl'), ['username']));
        $flags = array_values(array_filter(array_map(fn (PermissionFlag $f): ?string => $f->daAclColumn(), PermissionFlag::cases())));

        $this->assertEqualsCanonicalizing($columns, $flags);
    }

    public function test_there_are_29_flags_named_after_their_columns(): void
    {
        $this->assertCount(29, PermissionFlag::cases());

        foreach (PermissionFlag::cases() as $flag) {
            $column = $flag->userAclColumn() ?? $flag->daAclColumn();
            $this->assertNotNull($column);
            $this->assertSame(lcfirst(str_replace('_', '', ucwords((string) $column, '_'))), $flag->value);
        }
    }
}
