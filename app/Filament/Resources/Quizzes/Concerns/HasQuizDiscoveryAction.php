<?php

namespace App\Filament\Resources\Quizzes\Concerns;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;

trait HasQuizDiscoveryAction
{
    protected function quizDiscoveryAction(): Action
    {
        return Action::make('quizDiscovery')
            ->label('AI quiz interview')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('AI quiz interview')
            ->modalDescription('Chat to clarify the quiz, then say create the quiz now—or use Create quiz now—to generate an editable draft.')
            ->modalWidth(Width::Screen)
            ->modalContent(fn () => view('filament.actions.quiz-discovery-modal', [
                'quizId' => $this->quizDiscoveryQuizId(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    protected function quizDiscoveryQuizId(): ?int
    {
        return null;
    }
}
