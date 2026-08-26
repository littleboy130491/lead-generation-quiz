<?php

namespace Database\Seeders;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Enums\QuizResultMode;
use App\Enums\QuizStatus;
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
                'description' => 'Demo lead-generation quiz with opening page and AI analysis results.',
                'status' => QuizStatus::Draft,
                'draft_definition' => $this->definition(),
                'settings' => [
                    'collect_name' => true,
                    'collect_company' => false,
                    'collect_phone' => false,
                ],
            ],
        );

        if ($quiz->active_revision_id !== null) {
            return;
        }

        $quiz->fill([
            'name' => 'Business Readiness Check',
            'description' => 'Demo lead-generation quiz with opening page and AI analysis results.',
            'status' => QuizStatus::Draft,
            'draft_definition' => $this->definition(),
            'settings' => [
                'collect_name' => true,
                'collect_company' => false,
                'collect_phone' => false,
            ],
        ])->save();

        app(PublishQuizRevision::class)->handle($quiz->fresh(), $this->publisherId());
    }

    private function publisherId(): ?int
    {
        $admin = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'admin']))
            ->orderBy('id')
            ->value('id');

        return $admin !== null ? (int) $admin : User::query()->orderBy('id')->value('id');
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'schema_version' => 1,
            'opening' => [
                'html' => '<h1>Business Readiness Check</h1><p>Answer a few questions to receive tailored next steps by email.</p>',
                'start_button_label' => 'Start quiz',
                'hide_start_button' => false,
            ],
            'result' => [
                'mode' => QuizResultMode::Ai->value,
            ],
            'blocks' => [
                [
                    'id' => 'business-stage',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'What stage is your business in?',
                    'help' => 'Choose the option that best matches today.',
                    'required' => true,
                    'options' => [
                        ['id' => 'idea', 'value' => 'idea', 'label' => 'Idea or pre-revenue', 'score' => 1],
                        ['id' => 'launching', 'value' => 'launching', 'label' => 'Launching', 'score' => 2],
                        ['id' => 'growing', 'value' => 'growing', 'label' => 'Growing', 'score' => 3],
                    ],
                ],
                [
                    'id' => 'has-offer',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Do you already have a clear offer for customers?',
                    'required' => true,
                    'yes_score' => 2,
                    'no_score' => 0,
                ],
                [
                    'id' => 'primary-goal',
                    'type' => 'question',
                    'question_type' => 'short_text',
                    'label' => 'What is the most important goal for the next 90 days?',
                    'required' => true,
                    'max_length' => 500,
                ],
                ['id' => 'break-1', 'type' => 'page_break'],
                [
                    'id' => 'monthly-revenue',
                    'type' => 'question',
                    'question_type' => 'single_choice',
                    'label' => 'What is your current monthly revenue range?',
                    'required' => true,
                    'options' => [
                        ['id' => 'pre-revenue', 'value' => 'pre_revenue', 'label' => 'Pre-revenue', 'score' => 0],
                        ['id' => 'under-10k', 'value' => 'under_10k', 'label' => 'Under $10,000', 'score' => 2],
                        ['id' => 'over-10k', 'value' => 'over_10k', 'label' => '$10,000 or more', 'score' => 4],
                    ],
                ],
                [
                    'id' => 'priorities',
                    'type' => 'question',
                    'question_type' => 'multiple_choice',
                    'label' => 'Which areas need the most attention?',
                    'required' => true,
                    'options' => [
                        ['id' => 'leads', 'value' => 'leads', 'label' => 'Lead generation', 'score' => 1],
                        ['id' => 'delivery', 'value' => 'delivery', 'label' => 'Delivery / operations', 'score' => 1],
                        ['id' => 'cash', 'value' => 'cash', 'label' => 'Cash flow', 'score' => 1],
                    ],
                ],
                [
                    'id' => 'internal-note',
                    'type' => 'question',
                    'question_type' => 'long_text',
                    'label' => 'Anything else we should know? (not sent to AI analysis)',
                    'required' => false,
                    'max_length' => 2000,
                    'exclude_from_ai' => true,
                ],
            ],
        ];
    }
}
