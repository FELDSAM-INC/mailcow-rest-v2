<?php

namespace Tests\Feature\Schema;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 008 T005 (constitution III, data-model.md): the `imapsync` table of the loaded schema has
 * the columns and indexes of the selected supported upstream version. When an
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

    public function test_the_table_matches_the_supported_version_of_the_data_model(): void
    {
        $columns = array_map(
            fn (object $c): array => [(string) $c->Field, (string) $c->Type, (string) $c->Null, $c->Default === null ? null : (string) $c->Default, (string) $c->Extra],
            DB::select('SHOW COLUMNS FROM `imapsync`'),
        );

        $this->assertCount(34, self::COLUMNS);
        $expected = self::COLUMNS;
        if (SchemaVersion::hasNovember2026Features()) {
            $expected = [];
            foreach (self::COLUMNS as $column) {
                switch ($column[0]) {
                    case 'host1': $expected[] = ['source_id', 'int(11)', 'NO', null, ''];
                        break;
                    case 'authmech1': case 'port1': case 'enc1': break;
                    case 'password1': $expected[] = ['password1', 'varchar(255)', 'NO', '', ''];
                        break;
                    case 'custom_params':
                        $expected[] = ['custom_params', 'text', 'YES', null, ''];
                        $expected[] = ['custom_params_legacy', 'text', 'YES', null, ''];
                        break;
                    case 'is_running':
                        $expected[] = $column;
                        $expected[] = ['prio', 'int(11)', 'NO', '0', ''];
                        break;
                    default: $expected[] = $column;
                }
            }
        }
        $this->assertSame($expected, $columns);
    }

    public function test_the_indexes_match_the_supported_version(): void
    {
        $indexes = array_map(
            fn (object $i): string => $i->Key_name.'('.$i->Column_name.')',
            DB::select('SHOW INDEX FROM `imapsync`'),
        );

        $this->assertSame(SchemaVersion::hasNovember2026Features()
            ? ['PRIMARY(id)', 'idx_source_id(source_id)', 'idx_prio(prio)']
            : ['PRIMARY(id)'], $indexes);
    }
}
