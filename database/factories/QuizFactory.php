<?php

namespace Database\Factories;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    public function definition(): array
    {
        return ['name' => fake()->sentence(3), 'slug' => Str::lower(Str::random(12)), 'status' => QuizStatus::Draft, 'draft_definition' => ['schema_version' => 1, 'blocks' => []], 'settings' => []];
    }
}
