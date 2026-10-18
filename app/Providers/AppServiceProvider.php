<?php

namespace App\Providers;

use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Clients\Fail2Ban\RedisFail2BanReporter;
use App\Services\Config\MailcowConfig;
use App\Support\Clock\Sleeper;
use App\Support\Clock\UsleepSleeper;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailcowConfig::class, fn ($app) => new MailcowConfig($app->make(CacheFactory::class)->store('restv2')));
        $this->app->bind(Fail2BanReporter::class, RedisFail2BanReporter::class);
        $this->app->bind(Sleeper::class, UsleepSleeper::class);
    }

    public function boot(): void
    {
        // db:wipe, migrate:fresh, migrate:refresh, migrate:reset and migrate:rollback would drop
        // mailcow's own tables: prohibited in every environment (constitution III, R3).
        DB::prohibitDestructiveCommands();

        // mailcow's SESSION_LIFETIME (seconds) is the idle lifetime of browser sessions (R6).
        try {
            config(['session.lifetime' => (int) ceil($this->app->make(MailcowConfig::class)->sessionLifetime() / 60)]);
        } catch (Throwable) {
            // Keep the configured default when mailcow's configuration cannot be read at all.
        }
    }
}
