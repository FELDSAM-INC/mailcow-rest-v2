<?php

namespace App\Services\Monitoring;

/**
 * dockerapi's `system df` answer (feature 011 research R8): the last line of `df -H` with blanks
 * turned into commas, `device,size,used,available,use%,mount`. A long device name makes `df` wrap,
 * leaving the first field empty, so the last five fields are taken as figures and the rest as the
 * device. Sizes are SI-rounded (`107G`, powers of 1000) and are converted to approximate bytes.
 * `0,0,0,0,0,0` (dockerapi's failure answer), null and anything unparseable are an unavailable
 * measurement, never zeros.
 */
class DfOutputParser
{
    private const FACTORS = ['' => 1, 'k' => 1e3, 'K' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12, 'P' => 1e15, 'E' => 1e18];

    /**
     * @return array{measured: bool, device: string|null, totalBytes: int|null, usedBytes: int|null, availableBytes: int|null, usedPercent: int|null, approximate: bool}
     */
    public function parse(?string $line): array
    {
        $fields = $line === null ? [] : explode(',', trim($line));

        if (count($fields) < 6 || trim((string) $line) === '0,0,0,0,0,0') {
            return self::unavailable();
        }

        [$size, $used, $available, $percent] = array_slice($fields, -5, 4);
        $device = implode(',', array_slice($fields, 0, -5));
        $bytes = array_map(self::bytes(...), [$size, $used, $available]);

        if (in_array(null, $bytes, true) || preg_match('/^(\d{1,3})%$/', $percent, $m) !== 1 || (int) $m[1] > 100) {
            return self::unavailable();
        }

        return [
            'measured' => true,
            'device' => $device === '' ? null : $device,
            'totalBytes' => $bytes[0],
            'usedBytes' => $bytes[1],
            'availableBytes' => $bytes[2],
            'usedPercent' => (int) $m[1],
            'approximate' => true,
        ];
    }

    private static function bytes(string $value): ?int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)([kKMGTPE]?)$/', $value, $m) !== 1) {
            return null;
        }

        return (int) round((float) $m[1] * self::FACTORS[$m[2]]);
    }

    /**
     * @return array{measured: false, device: null, totalBytes: null, usedBytes: null, availableBytes: null, usedPercent: null, approximate: false}
     */
    private static function unavailable(): array
    {
        return ['measured' => false, 'device' => null, 'totalBytes' => null, 'usedBytes' => null, 'availableBytes' => null, 'usedPercent' => null, 'approximate' => false];
    }
}
