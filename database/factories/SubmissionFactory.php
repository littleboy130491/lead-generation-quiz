<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'status' => SubmissionStatus::InProgress, 'answers_snapshot' => [], 'metadata' => [], 'started_at' => now(), 'last_activity_at' => now(), 'expires_at' => now()->addDays(30)];
    }
}
