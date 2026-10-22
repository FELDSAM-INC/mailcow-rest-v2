<?php

namespace App\Providers;

use App\Clients\Development\NullDockerApiClient;
use App\Clients\Development\NullIspconfigDnsClient;
use App\Clients\Development\NullMemcachedFlusher;
use App\Clients\Development\NullPublicAddressLookup;
use App\Clients\Development\NullReleaseSource;
use App\Clients\Development\NullRspamdController;
use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\NativeDnsResolver;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\HttpDockerApiClient;
use App\Clients\Dovecot\DockerApiSieveScriptReader;
use App\Clients\Dovecot\SieveScriptReader;
use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Clients\Fail2Ban\RedisFail2BanReporter;
use App\Clients\Geo\CountryLookup;
use App\Clients\Geo\HttpCountryLookup;
use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Ispconfig\JsonIspconfigDnsClient;
use App\Clients\Keycloak\HttpKeycloakAdminClient;
use App\Clients\Keycloak\KeycloakAdminClient;
use App\Clients\Ldap\ExtLdapDirectory;
use App\Clients\Ldap\LdapDirectory;
use App\Clients\Lmtp\LmtpClient;
use App\Clients\Lmtp\SocketLmtpClient;
use App\Clients\Mail\QuarantineReleaseMailer;
use App\Clients\Mail\SmtpQuarantineReleaseMailer;
use App\Clients\Memcached\MemcachedFlusher;
use App\Clients\Memcached\SocketMemcachedFlusher;
use App\Clients\Oidc\LeagueOidcClient;
use App\Clients\Oidc\OidcClient;
use App\Clients\PublicAddress\HttpPublicAddressLookup;
use App\Clients\PublicAddress\PublicAddressLookup;
use App\Clients\Pushover\HttpPushoverClient;
use App\Clients\Pushover\PushoverClient;
use App\Clients\Releases\GitHubReleaseSource;
use App\Clients\Releases\ReleaseSource;
use App\Clients\Rspamd\HttpSettingsMapClient;
use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\SettingsMapClient;
use App\Clients\Rspamd\UnixSocketRspamdController;
use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\StreamSmtpProbe;
use App\Clients\Yubico\HttpYubicoOtpVerifier;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Models\Mailcow\Alias;
use App\Models\Mailcow\Domain;
use App\Models\Mailcow\DomainTemplate;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Models\Mailcow\Mailbox;
use App\Models\Mailcow\MailboxTemplate;
use App\Models\Mailcow\Oauth2Client;
use App\Policies\AliasPolicy;
use App\Policies\DkimKeyPolicy;
use App\Policies\DnsDiagnosticsPolicy;
use App\Policies\DomainPolicy;
use App\Policies\DomainTemplatePolicy;
use App\Policies\IdentityProviderPolicy;
use App\Policies\MailboxPolicy;
use App\Policies\MailboxTemplatePolicy;
use App\Policies\MonitoringPolicy;
use App\Policies\MtaStsPolicyPolicy;
use App\Policies\Oauth2ClientPolicy;
use App\Policies\SyncJobPolicy;
use App\Services\Accounts\AccountAuditTargets;
use App\Services\Aliases\AliasAuditTargets;
use App\Services\Audit\RefusalAuditor;
use App\Services\Auth\SessionRegistry;
use App\Services\Config\LegacyConfigLoader;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\DkimAuditTargets;
use App\Services\Domains\DomainAuditTargets;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\IdentityProvider\IdentityProviderAuditTargetResolver;
use App\Services\Mailboxes\MailboxAuditTargets;
use App\Services\Monitoring\MonitoringAuditTargetResolver;
use App\Services\Monitoring\SharedSnapshotCache;
use App\Services\Monitoring\UpdateCheckService;
use App\Services\MtaSts\MtaStsAuditTargets;
use App\Services\Oauth2\Oauth2ClientAuditTargetResolver;
use App\Services\Oauth2\Oauth2ClientService;
use App\Services\Quarantine\SpamFilterAuditTargets;
use App\Services\Routing\RoutingAuditTargets;
use App\Services\SelfService\SelfServiceAuditTargets;
use App\Services\Settings\SettingsAuditTargetResolver;
use App\Services\SyncJobs\SyncJobAuditTargets;
use App\Services\Webmail\WebmailHandOffService;
use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use App\Support\Clock\SystemClock;
use App\Support\Clock\UsleepSleeper;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LegacyConfigLoader::class, fn ($app) => new LegacyConfigLoader($app->make(CacheFactory::class)->store('restv2')));
        $this->app->singleton(MailcowConfig::class);
        $this->app->bind(Fail2BanReporter::class, RedisFail2BanReporter::class);
        $this->app->bind(Sleeper::class, UsleepSleeper::class);
        $this->app->bind(YubicoOtpVerifier::class, HttpYubicoOtpVerifier::class);
        $this->app->singleton(SharedSnapshotCache::class, fn ($app) => new SharedSnapshotCache($app->make(CacheFactory::class)->store('restv2')));

        // Outbound services of features 002 and 010, faked in tests (tests/TestCase.php) and, in
        // development only, with RESTV2_FAKE_OUTBOUND=y.
        $this->app->singleton(RefusalAuditor::class);
        // Feature 005: outbound services (faked in tests) and what ends with a session of this API
        // (the webmail hand-off).
        $this->app->bind(PushoverClient::class, HttpPushoverClient::class);
        $this->app->bind(CountryLookup::class, HttpCountryLookup::class);
        $this->app->bind(SieveScriptReader::class, DockerApiSieveScriptReader::class);
        $this->app->tag([WebmailHandOffService::class], SessionRegistry::LISTENER_TAG);
        $this->app->scoped(GroupwareCacheFlush::class);
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(DockerApiClient::class, HttpDockerApiClient::class);
        $this->app->bind(MemcachedFlusher::class, SocketMemcachedFlusher::class);
        $this->app->bind(IspconfigDnsClient::class, JsonIspconfigDnsClient::class);
        $this->app->bind(RspamdController::class, UnixSocketRspamdController::class);
        // Feature 013: mailcow's generated Rspamd settings map and Dovecot's local delivery of
        // mass mail (both faked in tests).
        $this->app->bind(SettingsMapClient::class, HttpSettingsMapClient::class);
        $this->app->bind(LmtpClient::class, fn () => new SocketLmtpClient(
            (string) config('restv2.lmtp.host'),
            (int) config('restv2.lmtp.port'),
            strtolower((string) config('restv2.mailcow_hostname')),
            (float) config('restv2.mass_mail.delivery_deadline'),
        ));
        // Feature 006: releases to Postfix's quarantine submission (faked in tests).
        $this->app->bind(QuarantineReleaseMailer::class, SmtpQuarantineReleaseMailer::class);
        $this->app->bind(ReleaseSource::class, GitHubReleaseSource::class);
        $this->app->bind(PublicAddressLookup::class, HttpPublicAddressLookup::class);
        $this->app->when(UpdateCheckService::class)->needs(Repository::class)->give(fn ($app) => $app->make(CacheFactory::class)->store('restv2'));
        // Feature 009: DNS and the SMTP connection test, faked in feature tests.
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(SmtpProbe::class, StreamSmtpProbe::class);

        // Feature 012: the identity provider's protocols, faked in tests (Http::fake(), FakeLdapDirectory).
        $this->app->bind(OidcClient::class, LeagueOidcClient::class);
        $this->app->bind(LdapDirectory::class, ExtLdapDirectory::class);
        $this->app->bind(KeycloakAdminClient::class, HttpKeycloakAdminClient::class);
        // OAuth2 client IDs: 12 lowercase hex characters from a cryptographically secure source.
        $this->app->bind(Oauth2ClientService::CLIENT_ID_GENERATOR, fn () => fn (): string => bin2hex(random_bytes(6)));

        if (config('restv2.fake_outbound')) {
            if ($this->app->environment('local')) {
                $this->app->bind(DockerApiClient::class, NullDockerApiClient::class);
                $this->app->bind(MemcachedFlusher::class, NullMemcachedFlusher::class);
                $this->app->bind(IspconfigDnsClient::class, NullIspconfigDnsClient::class);
                $this->app->bind(RspamdController::class, NullRspamdController::class);
                $this->app->bind(ReleaseSource::class, NullReleaseSource::class);
                $this->app->bind(PublicAddressLookup::class, NullPublicAddressLookup::class);
            } else {
                // Refused outside development: the real services are always used.
                error_log('RESTV2_FAKE_OUTBOUND is ignored outside APP_ENV=local.');
            }
        }
    }

    public function boot(): void
    {
        // db:wipe, migrate:fresh, migrate:refresh, migrate:reset and migrate:rollback would drop
        // mailcow's own tables: prohibited in every environment (constitution III, R3).
        DB::prohibitDestructiveCommands();

        Gate::policy(Domain::class, DomainPolicy::class);
        Gate::policy(DomainTemplate::class, DomainTemplatePolicy::class);
        Gate::policy(Mailbox::class, MailboxPolicy::class);
        Gate::policy(MailboxTemplate::class, MailboxTemplatePolicy::class);
        Gate::policy(Alias::class, AliasPolicy::class);
        Gate::define('syncJobs.use', [SyncJobPolicy::class, 'use']);
        Gate::policy(IdentityProviderSetting::class, IdentityProviderPolicy::class);
        Gate::policy(Oauth2Client::class, Oauth2ClientPolicy::class);

        // Refusal auditing: target resolvers of the features whose routes declare `restv2.audit`.
        DomainAuditTargets::register($this->app->make(RefusalAuditor::class));
        MailboxAuditTargets::register($this->app->make(RefusalAuditor::class));
        AliasAuditTargets::register($this->app->make(RefusalAuditor::class));
        MonitoringAuditTargetResolver::register($this->app->make(RefusalAuditor::class));
        IdentityProviderAuditTargetResolver::register($this->app->make(RefusalAuditor::class));
        Oauth2ClientAuditTargetResolver::register($this->app->make(RefusalAuditor::class));
        RoutingAuditTargets::register($this->app->make(RefusalAuditor::class));
        SelfServiceAuditTargets::register($this->app->make(RefusalAuditor::class));
        // Feature 007: accounts, single sign-on tokens, impersonations and webmail on behalf.
        AccountAuditTargets::register($this->app->make(RefusalAuditor::class));
        // Feature 006: quarantine, spam policy and rate limits.
        SpamFilterAuditTargets::register($this->app->make(RefusalAuditor::class));
        // Feature 013: system settings.
        SettingsAuditTargetResolver::register($this->app->make(RefusalAuditor::class));

        // Feature 011: administrators only (research R26).
        Gate::define('operate-monitoring', [MonitoringPolicy::class, 'operate']);

        // Feature 011 (research R24, FR-064): an operation carried out without its audit record.
        JsonResponse::macro('withAuditRecorded', function (bool $recorded): JsonResponse {
            /** @var JsonResponse $this */
            if (! $recorded) {
                $this->headers->set('Audit-Recorded', 'false');
            }

            return $this;
        });
        SyncJobAuditTargets::register($this->app->make(RefusalAuditor::class));

        // Feature 010: DKIM keys, MTA-STS policies and DNS diagnostics (FR-005), and their refusal
        // auditing (research R22).
        foreach (['viewAny', 'view', 'generate', 'overwrite', 'import', 'copy', 'delete', 'export', 'publish'] as $ability) {
            Gate::define("dkim.{$ability}", [DkimKeyPolicy::class, $ability]);
        }
        Gate::define('mtaSts.use', [MtaStsPolicyPolicy::class, 'use']);
        Gate::define('dnsDiagnostics.run', [DnsDiagnosticsPolicy::class, 'run']);
        Gate::define('dnsDiagnostics.serverChecks', [DnsDiagnosticsPolicy::class, 'serverChecks']);
        DkimAuditTargets::register($this->app->make(RefusalAuditor::class));
        MtaStsAuditTargets::register($this->app->make(RefusalAuditor::class));

        // mailcow's SESSION_LIFETIME (seconds) is the idle lifetime of browser sessions (R6).
        try {
            config(['session.lifetime' => (int) ceil($this->app->make(MailcowConfig::class)->sessionLifetime() / 60)]);
        } catch (Throwable) {
            // Keep the configured default when mailcow's configuration cannot be read at all.
        }
    }
}
