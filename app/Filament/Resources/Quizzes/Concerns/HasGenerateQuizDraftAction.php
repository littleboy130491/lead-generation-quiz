<?php

namespace App\Filament\Resources\Quizzes\Concerns;

use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Models\Quiz;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

trait HasGenerateQuizDraftAction
{
    protected function quizDiscoveryAction(): Action
    {
        return Action::make('quizDiscovery')
            ->label('AI quiz interview')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('AI quiz interview')
            ->modalDescription('Clarify the goal in a chat, review the structured brief, then create an editable quiz draft. Say "execute now" when you are ready to generate.')
            ->modalWidth(Width::Screen)
            ->modalContent(fn () => view('filament.actions.quiz-discovery-modal', [
                'quizId' => $this->resolveQuizIdForDiscovery(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    protected function resolveQuizIdForDiscovery(): ?int
    {
        $record = $this->getRecord();

        return $record instanceof Model ? (int) $record->getKey() : null;
    }

    protected function afterAiDraftGenerated(Quiz $quiz): void
    {
        if (! $this instanceof EditQuiz) {
            return;
        }

        $this->refreshFormData(['draft_definition', 'builder_blocks']);
        $this->fillForm();
    }
}
