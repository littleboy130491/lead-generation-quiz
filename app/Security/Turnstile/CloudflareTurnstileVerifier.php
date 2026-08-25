<?php

namespace App\Security\Turnstile;

use Illuminate\Support\Facades\Http;

class CloudflareTurnstileVerifier implements TurnstileVerifier
{
    public function verify(?string $token, ?string $ip): TurnstileVerification
    {
        if (! $token) {
            return new TurnstileVerification(false, 'missing_token', 'Verification token is required.');
        }
        try {
            $data = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter(['secret' => config('services.turnstile.secret_key'), 'response' => $token, 'remoteip' => $ip]))->json();

            return new TurnstileVerification((bool) ($data['success'] ?? false), ($data['error-codes'][0] ?? null), ($data['success'] ?? false) ? null : 'Turnstile verification failed.');
        } catch (\Throwable) {
            return new TurnstileVerification(false, 'turnstile_unavailable', 'Verification service is unavailable.');
        }
    }
}
