<?php

namespace Tests\Unit\MtaSts;

use App\Services\MtaSts\MtaStsPolicyText;
use PHPUnit\Framework\TestCase;

/**
 * Feature 010 T072 (FR-036, SC-008): the policy text is what legacy data/web/mta-sts.php serves for
 * the same stored row: `mailbox('get', 'mta_sts')` turns `stsv1` into `STSv1` and splits `mx` on
 * commas, and the endpoint prints one line per value with a line feed after each.
 */
class MtaStsPolicyTextTest extends TestCase
{
    /**
     * Legacy's output, reproduced from mta-sts.php and mailbox('get', 'mta_sts') for a stored row.
     *
     * @param  array{version: string, mode: string, max_age: string, mx: string}  $row
     */
    private static function legacy(array $row): string
    {
        $version = strtoupper(substr($row['version'], 0, 3)).substr($row['version'], 3);
        $text = "version: {$version}\n";
        $text .= "mode: {$row['mode']}\n";
        $text .= "max_age: {$row['max_age']}\n";

        foreach (explode(',', $row['mx']) as $mx) {
            $text .= "mx: {$mx}\n";
        }

        return $text;
    }

    public function test_the_text_of_a_stored_row_equals_legacy_s(): void
    {
        foreach ([
            ['version' => 'stsv1', 'mode' => 'enforce', 'max_age' => '86400', 'mx' => 'mail.example.org'],
            ['version' => 'stsv1', 'mode' => 'testing', 'max_age' => '604800', 'mx' => 'mail.example.org,*.example.net,mx2.xn--bcher-kva.example'],
            ['version' => 'stsv1', 'mode' => 'none', 'max_age' => '1', 'mx' => '*.example.org'],
        ] as $row) {
            $this->assertSame(self::legacy($row), MtaStsPolicyText::fromRow($row['version'], $row['mode'], $row['max_age'], $row['mx']));
        }

        $this->assertSame("version: STSv1\nmode: enforce\nmax_age: 86400\nmx: mail.example.org\nmx: *.example.org\n", MtaStsPolicyText::fromRow('stsv1', 'enforce', '86400', 'mail.example.org,*.example.org'));
    }
}
