<?php

namespace App\Security\Turnstile;

final readonly class TurnstileVerification
{
    public function __construct(public bool $accepted, public ?string $code = null, public ?string $message = null) {}
}
