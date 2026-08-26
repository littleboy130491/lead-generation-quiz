<?php

namespace App\Filament\Resources\Quizzes\Concerns;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Ai\ConfiguredAiProviders;
use App\Ai\GenerationException;
use App\Enums\QuizStatus;
use App\Models\Quiz;
use App\Settings\ApplicationSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

trait HasGenerateQuizDraftAction
{
    protected function generateQuizDraftAction(): Action
    {
        return Action::make('generateDraft')
            ->label('Generate AI draft')
            ->form([
                Textarea::make('business_context')->required()->maxLength(4000),
                TextInput::make('target_audience')->maxLength(500),
                TextInput::make('objective')->maxLength(500),
                TextInput::make('desired_insight')->maxLength(500),
                TextInput::make('question_count')->numeric()->minValue(1)->maxValue(30),
                TextInput::make('tone')->maxLength(100),
            ])
            ->modalDescription(fn (): ?string => $this->quizAiIsConfigured()
                ? null
                : ConfiguredAiProviders::SCAFFOLD_ADMIN_GUIDANCE)
            ->action(function (array $data): void {
                $usedAi = $this->quizAiIsConfigured();

                try {
                    $quiz = $this->quizForAiDraftGeneration($data);
                    app(GenerateQuizDraft::class)->handle($quiz, $data);
                } catch (GenerationException $exception) {
                    if (! app()->runningUnitTests()) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }

                    return;
                }

                if (! app()->runningUnitTests()) {
                    Notification::make()->success()->title(
                        $usedAi ? 'AI draft generated.' : 'Structural draft generated.'
                    )->send();
                }

                $this->afterAiDraftGenerated($quiz);
            });
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    protected function quizForAiDraftGeneration(array $brief): Quiz
    {
        return $this->getRecord();
    }

    protected function afterAiDraftGenerated(Quiz $quiz): void
    {
        $this->refreshFormData(['draft_definition', 'builder_blocks']);
        $this->fillForm();
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    protected function createQuizForAiDraftGeneration(array $brief): Quiz
    {
        $name = filled($brief['objective'] ?? null)
            ? Str::limit((string) $brief['objective'], 80, '')
            : 'AI draft quiz';

        return Quiz::query()->create([
            'name' => $name !== '' ? $name : 'AI draft quiz',
            'slug' => Str::lower(Str::random(12)),
            'status' => QuizStatus::Draft,
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
            'settings' => [],
        ]);
    }

    protected function quizAiIsConfigured(): bool
    {
        return app(ConfiguredAiProviders::class)->hasUsableCredentials(
            app(ApplicationSettings::class)->get('ai.quiz')
        );
    }
}
