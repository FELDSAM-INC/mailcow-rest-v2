<?php

namespace Tests\Feature\Settings;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 013 T013 (FR-089): the problem types of the system settings render as problem+json with
 * their status, English title and extension members, and each one's documentation is served at its
 * URI.
 */
class SettingsProblemTypesTest extends TestCase
{
    /**
     * @return array<string, array{ProblemType, int, string, array<string, mixed>}>
     */
    public static function types(): array
    {
        return [
            'demo-mode' => [ProblemType::DemoMode, 403, 'Demo mode', []],
            'feature-disabled' => [ProblemType::FeatureDisabled, 403, 'Feature disabled', []],
            'regex-map-unavailable' => [ProblemType::RegexMapUnavailable, 409, 'Regex map unavailable', []],
            'global-sieve-filter-unavailable' => [ProblemType::GlobalSieveFilterUnavailable, 409, 'Global Sieve filter unavailable', []],
            'mass-mail-already-running' => [ProblemType::MassMailAlreadyRunning, 409, 'Mass mail already running', ['runningJobId' => '01JABCDEFGHJKMNPQRSTVWXYZ0']],
            'mass-mail-recipient-count-changed' => [ProblemType::MassMailRecipientCountChanged, 409, 'Mass mail recipient count changed', ['actualRecipients' => 122]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    #[DataProvider('types')]
    public function test_the_type_renders_with_its_status_title_and_members(ProblemType $type, int $status, string $title, array $extensions): void
    {
        Route::middleware('api.v2')->get('/api/v2/__testing/settings-problem', fn () => throw new ProblemException($type, null, $extensions));

        $response = $this->getJson('/api/v2/__testing/settings-problem')
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $type->uri())
            ->assertJsonPath('title', $title)
            ->assertJsonPath('status', $status);

        $this->assertSame($type->detail(), $response->json('detail'));

        foreach ($extensions as $member => $value) {
            $response->assertJsonPath($member, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    #[DataProvider('types')]
    public function test_the_type_is_documented_at_its_uri(ProblemType $type, int $status, string $title, array $extensions): void
    {
        $this->get($type->uri())
            ->assertOk()
            ->assertSee('<h2>'.$type->value.'</h2>', false)
            ->assertSee((string) $status);
    }
}
