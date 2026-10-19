<?php

namespace App\Services\Settings;

use App\Services\Config\MailcowConfig;
use App\Support\Color\WcagContrast;
use App\Support\Images\ImageInspector;

/**
 * The settings capabilities (feature 013 FR-094, research R23): the server settings and limits the
 * system settings forms need before they submit, assembled from the current configuration on every
 * request. No secret and no connection detail: no LMTP host, no license or settings map address,
 * only the relative address of the Rspamd web interface.
 */
class SettingsCapabilitiesService
{
    public function __construct(private readonly MailcowConfig $config) {}

    /**
     * Contract `SettingsCapabilities`.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $hostname = self::hostname();
        $logos = $this->config->logoLimits();

        return [
            'hostname' => $hostname,
            'demoMode' => config('restv2.demo_mode') === true,
            'licenseVerificationEnabled' => config('restv2.license.enabled') === true,
            'rspamdWebInterfaceUrl' => (string) config('restv2.rspamd_ui_url'),
            'logos' => [
                'maxBytes' => $logos['max_size'],
                'maxWidth' => $logos['max_width'],
                'maxHeight' => $logos['max_height'],
                'acceptedMediaTypes' => ImageInspector::ACCEPTED,
            ],
            'brandColors' => [
                'minimumContrastRatio' => WcagContrast::MINIMUM,
                'lightReferenceSurface' => WcagContrast::LIGHT_SURFACE,
                'darkReferenceSurface' => WcagContrast::DARK_SURFACE,
            ],
            'limits' => SettingsLimits::toArray(),
            'massMail' => [
                'suggestedSender' => 'noreply@'.$hostname,
                'ratePerSecond' => (int) config('restv2.mass_mail.rate'),
                'maxRunningJobs' => SettingsLimits::MASS_MAIL_MAX_RUNNING_JOBS,
                'maxRetries' => SettingsLimits::MASS_MAIL_MAX_RETRIES,
                'retryWindowSeconds' => SettingsLimits::MASS_MAIL_RETRY_WINDOW_SECONDS,
                'maxSelectionEntries' => SettingsLimits::MASS_MAIL_SELECTION_ENTRIES_MAX,
                'maxBodyBytes' => SettingsLimits::MASS_MAIL_BODY_MAX_BYTES,
            ],
        ];
    }

    /** `MAILCOW_HOSTNAME`, lower-cased as legacy uses it. */
    public static function hostname(): string
    {
        return strtolower((string) config('restv2.mailcow_hostname'));
    }
}
