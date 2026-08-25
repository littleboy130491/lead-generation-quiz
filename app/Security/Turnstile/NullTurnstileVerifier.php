<?php

namespace App\Security\Turnstile;

class NullTurnstileVerifier implements TurnstileVerifier
{
    public function verify(?string $token, ?string $ip): TurnstileVerification
    {
        return config('quiz.turnstile.required')
            ? new TurnstileVerification(false, 'turnstile_required', 'Verification is required.')
            : new TurnstileVerification(true);
    }
}
