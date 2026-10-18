<?php

namespace App\Providers;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\HttpDockerApiClient;
use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Clients\Fail2Ban\RedisFail2BanReporter;
use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Ispconfig\JsonIspconfigDnsClient;
use App\Clients\Memcached\MemcachedFlusher;
use App\Clients\Memcached\SocketMemcachedFlusher;
use App\Clients\Yubico\HttpYubicoOtpVerifier;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Models\Mailcow\Domain;
use App\Models\Mailcow\DomainTemplate;
use App\Policies\DomainPolicy;
use App\Policies\DomainTemplatePolicy;
use App\Services\Config\MailcowConfig;
use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use App\Support\Clock\SystemClock;
use App\Support\Clock\UsleepSleeper;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailcowConfig::class, fn ($app) => new MailcowConfig($app->make(CacheFactory::class)->store('restv2')));
        $this->app->bind(Fail2BanReporter::class, RedisFail2BanReporter::class);
        $this->app->bind(Sleeper::class, UsleepSleeper::class);
        $this->app->bind(YubicoOtpVerifier::class, HttpYubicoOtpVerifier::class);

        // Outbound services of features 002 and 010, faked in tests (tests/TestCase.php) and, in
        // development only, with RESTV2_FAKE_OUTBOUND=y.
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(DockerApiClient::class, HttpDockerApiClient::class);
        $this->app->bind(MemcachedFlusher::class, SocketMemcachedFlusher::class);
        $this->app->bind(IspconfigDnsClient::class, JsonIspconfigDnsClient::class);
    }

    public function boot(): void
    {
        // db:wipe, migrate:fresh, migrate:refresh, migrate:reset and migrate:rollback would drop
        // mailcow's own tables: prohibited in every environment (constitution III, R3).
        DB::prohibitDestructiveCommands();

        Gate::policy(Domain::class, DomainPolicy::class);
        Gate::policy(DomainTemplate::class, DomainTemplatePolicy::class);

        // mailcow's SESSION_LIFETIME (seconds) is the idle lifetime of browser sessions (R6).
        try {
            config(['session.lifetime' => (int) ceil($this->app->make(MailcowConfig::class)->sessionLifetime() / 60)]);
        } catch (Throwable) {
            // Keep the configured default when mailcow's configuration cannot be read at all.
        }
    }
}
