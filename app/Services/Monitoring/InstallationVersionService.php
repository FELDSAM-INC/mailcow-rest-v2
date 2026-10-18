<?php

namespace App\Services\Monitoring;

use App\Services\Config\MailcowConfig;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The installed version as the legacy UI shows it (feature 011 research R9, legacy
 * `get/status/version`): read from app_info.inc.php through 001's configuration reader, with the
 * commit date (`git log --format=%ci`, for example `2026-09-15 10:11:12 +0200`) converted to UTC.
 * Values longer than the contract allows, and addresses that are not http(s) URIs, are null.
 */
class InstallationVersionService
{
    public function __construct(private readonly MailcowConfig $config) {}

    public function current(): InstallationVersion
    {
        $info = $this->config->appInfo();

        return new InstallationVersion(
            tag: self::bounded($info['version'], 255),
            branch: self::bounded($info['branch'], 255),
            commit: self::bounded($info['commit'], 64),
            commitDate: self::commitDate($info['commitDate']),
            projectUrl: $info['url'] !== null && preg_match('#^https?://[^\s]+$#i', $info['url']) === 1 ? $info['url'] : null,
        );
    }

    private static function bounded(?string $value, int $max): ?string
    {
        return $value !== null && mb_strlen($value) <= $max ? $value : null;
    }

    private static function commitDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d H:i:s O', 'Y-m-d H:i:s P', 'Y-m-d\TH:i:sP'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);

                if ($date instanceof CarbonImmutable) {
                    return $date->utc()->format('Y-m-d\TH:i:s\Z');
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return null;
    }
}
