<?php

namespace Tests\Feature\PasswordPolicy;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Passwords\PasswordPolicyReader;
use App\Support\Http\Base64Url;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T007 (FR-063, research R4): the effective password policy comes from Redis
 * `PASSWD_POLICY` on every call, with the legacy defaults for rules never saved, and its version tag
 * is the SHA-256 of the canonical string feature 013 uses too.
 */
class PasswordPolicyReaderTest extends TestCase
{
    use DomainTestHelpers;

    private function reader(): PasswordPolicyReader
    {
        return $this->app->make(PasswordPolicyReader::class);
    }

    private static function tag(string $canonical): string
    {
        return '"'.Base64Url::encode(hash('sha256', $canonical, true)).'"';
    }

    public function test_an_unsaved_policy_has_the_legacy_defaults(): void
    {
        $policy = $this->reader()->effective();

        $this->assertSame(6, $policy->minLength);
        $this->assertFalse($policy->requireLetters);
        $this->assertFalse($policy->requireDigits);
        $this->assertFalse($policy->requireSpecialCharacters);
        $this->assertFalse($policy->requireMixedCase);
        $this->assertSame(self::tag('length=6;chars=0;special_chars=0;lowerupper=0;numbers=0'), $policy->etag());
    }

    public function test_stored_values_are_read(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '10', 'numbers' => '1']);

        $policy = $this->reader()->effective();

        $this->assertSame(10, $policy->minLength);
        $this->assertTrue($policy->requireDigits);
        $this->assertFalse($policy->requireLetters);
        $this->assertSame(self::tag('length=10;chars=0;special_chars=0;lowerupper=0;numbers=1'), $policy->etag());
    }

    public function test_invalid_values_fall_back_like_legacy(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => 'abc', 'chars' => '2', 'special_chars' => '1', 'lowerupper' => '1']);

        $policy = $this->reader()->effective();

        $this->assertSame(6, $policy->minLength);
        $this->assertFalse($policy->requireLetters);
        $this->assertTrue($policy->requireSpecialCharacters);
        $this->assertTrue($policy->requireMixedCase);
    }

    public function test_the_tag_changes_with_every_field(): void
    {
        $tags = [$this->reader()->effective()->etag()];

        foreach (['length' => '8', 'chars' => '1', 'special_chars' => '1', 'lowerupper' => '1', 'numbers' => '1'] as $field => $value) {
            $this->redis()->hSet('PASSWD_POLICY', $field, $value);
            $tags[] = $this->reader()->effective()->etag();
        }

        $this->assertCount(6, array_unique($tags));
    }

    public function test_an_unavailable_store_raises_the_shared_store_failure(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->expectException(SharedStoreUnavailable::class);
        $this->reader()->effective();
    }
}
