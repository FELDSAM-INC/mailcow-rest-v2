<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `createPasswordResetWebAuthnChallenge`: the token; counts as a token check (FR-026).
 */
class PasswordResetWebAuthnChallengeRequest extends CheckPasswordResetTokenRequest {}
