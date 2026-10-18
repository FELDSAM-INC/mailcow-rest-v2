<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 008 T005 (constitution III, data-model.md): the `imapsync` table of the loaded schema has
 * exactly the 34 columns the model, the version tag and the contract are built on, and no index but
 * its primary key (the `(user2, user1, host1)` uniqueness is enforced in code, research R9). When an
 * upstream release changes the table, this test fails, so that the feature is updated deliberately.
 */
class ImapsyncColumnsTest extends TestCase
{
    /** @var list<array{string, string, string, string|null, string}> name, type, nullable, default, extra */
    private const COLUMNS = [
        ['id', 'int(11)', 'NO', null, 'auto_increment'],
        ['user2', 'varchar(255)', 'NO', null, ''],
        ['host1', 'varchar(255)', 'NO', null, ''],
        ['authmech1', "enum('PLAIN','LOGIN','CRAM-MD5')", 'YES', 'PLAIN', ''],
        ['regextrans2', 'varchar(255)', 'YES', '', ''],
        ['authmd51', 'tinyint(1)', 'NO', '0', ''],
        ['domain2', 'varchar(255)', 'NO', '', ''],
        ['subfolder2', 'varchar(255)', 'NO', '', ''],
        ['user1', 'varchar(255)', 'NO', null, ''],
        ['password1', 'varchar(255)', 'NO', null, ''],
        ['exclude', 'varchar(500)', 'NO', '', ''],
        ['maxage', 'smallint(6)', 'NO', '0', ''],
        ['mins_interval', 'smallint(5) unsigned', 'NO', '0', ''],
        ['maxbytespersecond', 'varchar(50)', 'NO', '0', ''],
        ['port1', 'smallint(5) unsigned', 'NO', null, ''],
        ['enc1', "enum('TLS','SSL','PLAIN')", 'YES', 'TLS', ''],
        ['delete2duplicates', 'tinyint(1)', 'NO', '1', ''],
        ['delete1', 'tinyint(1)', 'NO', '0', ''],
        ['delete2', 'tinyint(1)', 'NO', '0', ''],
        ['automap', 'tinyint(1)', 'NO', '0', ''],
        ['skipcrossduplicates', 'tinyint(1)', 'NO', '0', ''],
        ['custom_params', 'varchar(512)', 'NO', '', ''],
        ['timeout1', 'smallint(6)', 'NO', '600', ''],
        ['timeout2', 'smallint(6)', 'NO', '600', ''],
        ['subscribeall', 'tinyint(1)', 'NO', '1', ''],
        ['dry', 'tinyint(1)', 'NO', '0', ''],
        ['is_running', 'tinyint(1)', 'NO', '0', ''],
        ['returned_text', 'longtext', 'YES', null, ''],
        ['last_run', 'timestamp', 'YES', null, ''],
        ['success', 'tinyint(1) unsigned', 'YES', null, ''],
        ['exit_status', 'varchar(50)', 'YES', null, ''],
        ['created', 'datetime', 'NO', 'current_timestamp()', ''],
        ['modified', 'datetime', 'YES', null, 'on update current_timestamp()'],
        ['active', 'tinyint(1)', 'NO', '0', ''],
    ];

    public function test_the_table_has_exactly_the_34_columns_of_the_data_model(): void
    {
        $columns = array_map(
            fn (object $c): array => [(string) $c->Field, (string) $c->Type, (string) $c->Null, $c->Default === null ? null : (string) $c->Default, (string) $c->Extra],
            DB::select('SHOW COLUMNS FROM `imapsync`'),
        );

        $this->assertCount(34, self::COLUMNS);
        $this->assertSame(self::COLUMNS, $columns);
    }

    public function test_the_only_index_is_the_primary_key(): void
    {
        $indexes = array_map(
            fn (object $i): string => $i->Key_name.'('.$i->Column_name.')',
            DB::select('SHOW INDEX FROM `imapsync`'),
        );

        $this->assertSame(['PRIMARY(id)'], $indexes);
    }
}
