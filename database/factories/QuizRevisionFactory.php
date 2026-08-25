<?php

namespace Database\Factories;

use App\Models\QuizRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuizRevisionFactory extends Factory
{
    protected $model = QuizRevision::class;

    public function definition(): array
    {
        return ['version' => 1, 'definition' => ['schema_version' => 1, 'blocks' => []], 'published_at' => now()];
    }
}
