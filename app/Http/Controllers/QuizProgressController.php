<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;

class QuizProgressController extends Controller
{
    public function contact(Quiz $quiz, Submission $submission)
    {
        abort_unless($submission->quiz_id === $quiz->id && $submission->status === SubmissionStatus::AwaitingContact, 403);

        return view('quiz.contact', compact('quiz', 'submission'));
    }
}
