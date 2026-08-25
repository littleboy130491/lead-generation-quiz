<?php

namespace Database\Seeders;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeadGenerationQuizSeeder extends Seeder
{
    /**
     * Create an idempotent, publicly accessible example used for local/demo testing.
     * Existing published revisions are never altered.
     */
    public function run(): void
    {
        $quiz = Quiz::query()->firstOrCreate(
            ['slug' => 'business-readiness-check'],
            [
                'name' => 'Business Readiness Check',
                'draft_definition' => $this->definition(),
                'settings' => [],
            ],
        );

        if ($quiz->active_revision_id === null) {
            app(PublishQuizRevision::class)->handle(
                $quiz,
                User::query()->where('email', 'henry@demo-ku.com')->value('id'),
            );
        }
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [
                [
                    'id' => 'welcome',
                    'type' => 'content',
                    'markdown' => "## Business Readiness Check\nAnswer a few questions to receive your tailored next steps.",
                ],
                [
                    'id' => 'business-stage',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'What stage is your business in?',
                    'required' => true,
                    'options' => [
                        ['id' => 'idea', 'value' => 'idea', 'label' => 'Idea or pre-revenue'],
                        ['id' => 'launching', 'value' => 'launching', 'label' => 'Launching'],
                        ['id' => 'growing', 'value' => 'growing', 'label' => 'Growing'],
                    ],
                ],
                [
                    'id' => 'primary-goal',
                    'type' => 'question',
                    'question_type' => 'short_text',
                    'label' => 'What is the most important goal for the next 90 days?',
                    'required' => true,
                ],
                ['id' => 'break-1', 'type' => 'page_break'],
                [
                    'id' => 'monthly-revenue',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'What is your current monthly revenue range?',
                    'required' => true,
                    'options' => [
                        ['id' => 'pre-revenue', 'value' => 'pre_revenue', 'label' => 'Pre-revenue'],
                        ['id' => 'under-10k', 'value' => 'under_10k', 'label' => 'Under $10,000'],
                        ['id' => 'over-10k', 'value' => 'over_10k', 'label' => '$10,000 or more'],
                    ],
                ],
            ],
        ];
    }
}
