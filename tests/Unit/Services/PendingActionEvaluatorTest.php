<?php

namespace Tests\Unit\Services;

use App\Auth\AccountKind;
use App\Auth\PendingAction;
use App\Services\Auth\PendingActionEvaluator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T030 (FR-014, FR-054, R16): pending actions come from mailcow's current data.
 */
class PendingActionEvaluatorTest extends TestCase
{
    private function evaluator(): PendingActionEvaluator
    {
        return $this->app->make(PendingActionEvaluator::class);
    }

    /**
     * Creates an account of the given kind with the given attributes; returns [kind, username].
     *
     * @param  array<string, mixed>  $attributes
     * @return array{AccountKind, string}
     */
    private function account(string $type, array $attributes): array
    {
        return match ($type) {
            'admin' => [AccountKind::Admin, tap('admin', fn () => $this->mailcow()->admin('admin', 'x', attributes: $attributes))],
            'domainAdmin' => [AccountKind::Admin, tap('reseller', function () use ($attributes): void {
                $this->mailcow()->domain('example.org');
                $this->mailcow()->domainAdmin('reseller', ['example.org'], attributes: $attributes);
            })],
            default => [AccountKind::Mailbox, tap('alice@example.org', fn () => $this->mailcow()->mailboxWithDomain('alice@example.org', $attributes))],
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountTypes(): array
    {
        return ['admin' => ['admin'], 'domain admin' => ['domainAdmin'], 'mailbox' => ['mailbox']];
    }

    #[DataProvider('accountTypes')]
    public function test_a_forced_password_change_as_string_or_integer(string $type): void
    {
        [$kind, $username] = $this->account($type, ['force_pw_update' => '1']);
        $this->assertSame([PendingAction::PasswordChange], $this->evaluator()->forAccount($kind, $username));

        $table = $kind === AccountKind::Admin ? 'admin' : 'mailbox';
        DB::table($table)->where('username', $username)->update(['attributes' => json_encode(['force_pw_update' => 1, 'force_tfa' => 0])]);
        $this->assertSame([PendingAction::PasswordChange], $this->evaluator()->forAccount($kind, $username));

        DB::table($table)->where('username', $username)->update(['attributes' => json_encode(['force_pw_update' => '0', 'force_tfa' => '0'])]);
        $this->assertSame([], $this->evaluator()->forAccount($kind, $username));
    }

    #[DataProvider('accountTypes')]
    public function test_forced_second_factor_setup_without_any_second_factor(string $type): void
    {
        [$kind, $username] = $this->account($type, ['force_tfa' => '1']);

        $this->assertSame([PendingAction::SecondFactorSetup], $this->evaluator()->forAccount($kind, $username));
    }

    #[DataProvider('accountTypes')]
    public function test_an_inactive_second_factor_counts_as_existing(string $type): void
    {
        [$kind, $username] = $this->account($type, ['force_tfa' => '1']);
        $this->mailcow()->tfaTotp($username, 'JBSWY3DPEHPK3PXP', active: 0);

        $this->assertSame([], $this->evaluator()->forAccount($kind, $username));
    }

    #[DataProvider('accountTypes')]
    public function test_only_an_active_passkey_lifts_second_factor_setup(string $type): void
    {
        [$kind, $username] = $this->account($type, ['force_tfa' => '1']);
        $credential = (new VirtualAuthenticator)->createCredential('mail.example.org', $username);

        $this->mailcow()->fido2Credential($username, $credential, active: 0);
        $this->assertSame([PendingAction::SecondFactorSetup], $this->evaluator()->forAccount($kind, $username));

        DB::table('fido2')->where('username', $username)->update(['active' => 1]);
        $this->assertSame([], $this->evaluator()->forAccount($kind, $username));
    }

    #[DataProvider('accountTypes')]
    public function test_both_actions_can_apply(string $type): void
    {
        [$kind, $username] = $this->account($type, ['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->assertSame([PendingAction::PasswordChange, PendingAction::SecondFactorSetup], $this->evaluator()->forAccount($kind, $username));
    }

    public function test_no_flags_means_no_pending_actions(): void
    {
        [$kind, $username] = $this->account('mailbox', []);

        $this->assertSame([], $this->evaluator()->forAccount($kind, $username));
    }
}
