<?php

namespace App\Http\Middleware;

use App\Models\Submission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeQuizSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $submission = $request->route('submission');
        if (! $submission instanceof Submission) {
            abort(404);
        }

        $sessionMatches = (int) $request->session()->get('quiz_submission.'.$submission->quiz_id, 0) === $submission->id;
        $token = $request->cookie('quiz_resume_'.$submission->quiz_id);
        $cookieMatches = is_string($token) && hash_equals($submission->resume_token_hash ?? '', hash('sha256', $token));
        abort_unless($sessionMatches || $cookieMatches, 403);

        return $next($request);
    }
}
