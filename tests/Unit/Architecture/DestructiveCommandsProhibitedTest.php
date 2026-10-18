<?php

namespace Tests\Unit\Architecture;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/**
 * T022 (R3, R5): db:wipe, migrate:fresh, migrate:refresh and migrate:reset drop every table of the
 * database, mailcow's included. They are prohibited in every environment. The commands are never
 * run here: the prohibition flag is read directly.
 */
class DestructiveCommandsProhibitedTest extends TestCase
{
    private const COMMANDS = [
        'db:wipe' => WipeCommand::class,
        'migrate:fresh' => FreshCommand::class,
        'migrate:refresh' => RefreshCommand::class,
        'migrate:reset' => ResetCommand::class,
    ];

    #[DataProvider('environments')]
    public function test_destructive_commands_are_prohibited(string $environment): void
    {
        foreach (self::COMMANDS as $class) {
            $class::prohibit(false);
        }

        $this->app['env'] = $environment;
        (new AppServiceProvider($this->app))->boot();

        foreach (self::COMMANDS as $name => $class) {
            $this->assertTrue(
                (new ReflectionProperty($class, 'prohibitedFromRunning'))->getValue(),
                "{$name} must be prohibited in {$environment}",
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function environments(): array
    {
        return ['testing' => ['testing'], 'local' => ['local'], 'production' => ['production']];
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }
}
