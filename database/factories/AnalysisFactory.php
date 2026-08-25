<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Models\Analysis;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'sequence' => 1, 'status' => AnalysisStatus::Queued, 'trigger' => AnalysisTrigger::Manual, 'created_manually' => true, 'requested_provider_chain' => [], 'input_snapshot' => [], 'attempt_count' => 0];
    }
}
