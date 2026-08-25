<?php

namespace App\Actions\Quizzes;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use Illuminate\Support\Str;

class DuplicateQuiz
{
    public function handle(Quiz $source, ?string $name = null): Quiz
    {
        $name ??= $source->name.' copy';
        $baseSlug = Str::slug($source->slug.'-copy');
        $slug = $baseSlug;
        $suffix = 2;

        while (Quiz::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return Quiz::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $source->description,
            'status' => QuizStatus::Draft,
            'draft_definition' => $source->draft_definition,
            'settings' => $source->settings,
            'created_by' => auth()->id(),
        ]);
    }
}
