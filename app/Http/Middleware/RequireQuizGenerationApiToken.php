<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireQuizGenerationApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('quiz_api.token');
        $providedToken = $request->bearerToken();

        if (! is_string($configuredToken) || $configuredToken === '' || ! is_string($providedToken) || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'A valid Bearer token is required.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
