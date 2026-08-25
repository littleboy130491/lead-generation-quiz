<?php

namespace App\Security\Turnstile;

interface TurnstileVerifier
{
    public function verify(?string $token, ?string $ip): TurnstileVerification;
}
