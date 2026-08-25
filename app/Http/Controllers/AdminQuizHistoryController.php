<?php

namespace App\Http\Controllers;

use App\Domain\Quiz\Pagination\QuizPageCompiler;
use App\Models\Quiz;
use Illuminate\Http\Request;

class AdminQuizHistoryController extends Controller
{
    public function preview(Request $request, Quiz $quiz)
    {
        abort_unless($request->user(), 403);
        $revision = $request->integer('revision') ? $quiz->revisions()->findOrFail($request->integer('revision')) : null;
        $definition = $revision?->definition ?? $quiz->draft_definition ?? ['schema_version' => 1, 'blocks' => []];
        $pages = $definition['blocks'] === [] ? [] : app(QuizPageCompiler::class)->compile($definition);

        return view('admin.quizzes.preview', compact('quiz', 'revision', 'definition', 'pages'));
    }

    public function history(Request $request, Quiz $quiz)
    {
        abort_unless($request->user(), 403);

        return view('admin.quizzes.history', ['quiz' => $quiz, 'revisions' => $quiz->revisions()->latest('version')->get()]);
    }
}
