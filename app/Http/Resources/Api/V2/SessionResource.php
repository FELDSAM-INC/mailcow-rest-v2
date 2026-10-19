<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\PendingAction;
use App\Auth\SessionState;
use App\Models\Mailcow\TfaEntry;
use App\Services\Auth\SessionManager;
use App\Services\Config\MailcowConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Session`: `state`, `idleTimeout` and `pendingActions` always; `username` and `role`
 * except when anonymous; `authMethod` and `signedInAt` when signed in; `pendingSignIn` (the usable
 * second factors in presentation order and the expiry) during a pending sign-in.
 *
 * @property array<string, mixed> $resource
 */
class SessionResource extends JsonResource
{
    /** Second-factor types of the contract by mailcow `tfa.authmech`. */
    private const METHOD_TYPES = ['webauthn' => 'webauthn', 'totp' => 'totp', 'yubi_otp' => 'yubiOtp'];

    public static function fromSession(Session $session): self
    {
        $sessions = app(SessionManager::class);
        $state = $sessions->state($session);
        $data = ['state' => $state->value];

        if ($state === SessionState::Restricted || $state === SessionState::Active) {
            $principal = $sessions->effectivePrincipal($session);
            assert($principal !== null);
            // Feature 007: during an impersonation the target's username and role, the
            // impersonator's sign-in method and time.
            $data += [
                'username' => $principal->username,
                'role' => $principal->role->value,
                'authMethod' => $principal->authMethod->value,
                'signedInAt' => self::time($principal->signedInAt ?? CarbonImmutable::now('UTC')),
            ];

            if ($principal->impersonator !== null) {
                $data['impersonation'] = ImpersonationResource::present($principal);
            }
        }

        if ($state === SessionState::PendingSecondFactor) {
            $pending = $sessions->pending($session);
            assert($pending !== null);
            $data += [
                'username' => $pending['username'],
                'role' => $pending['role']->value,
                'pendingSignIn' => [
                    'methods' => self::methods($pending['username'], $pending['methodIds']),
                    'expiresAt' => self::time($pending['expiresAt']),
                ],
            ];
        }

        $data['idleTimeout'] = app(MailcowConfig::class)->sessionLifetime();
        $data['pendingActions'] = array_map(fn (PendingAction $a): string => $a->value, $sessions->pendingActions($session));

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * The pending sign-in's methods that are still active, in the stored order.
     *
     * @param  list<int>  $methodIds
     * @return list<array{id: int, type: string, name: string, tier: string}>
     */
    private static function methods(string $username, array $methodIds): array
    {
        $rows = TfaEntry::query()
            ->where('username', $username)
            ->where('active', 1)
            ->whereIn('id', $methodIds)
            ->whereIn('authmech', array_keys(self::METHOD_TYPES))
            ->get()
            ->keyBy('id');

        $methods = [];

        foreach ($methodIds as $id) {
            $row = $rows->get($id);

            if ($row instanceof TfaEntry) {
                $type = self::METHOD_TYPES[(string) $row->authmech];
                $methods[] = [
                    'id' => $row->id,
                    'type' => $type,
                    'name' => $row->key_id,
                    'tier' => $type === 'yubiOtp' ? 'advanced' : 'primary',
                ];
            }
        }

        return $methods;
    }

    private static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
