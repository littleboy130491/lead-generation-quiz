<?php

namespace App\Filament\Resources\Quizzes\Concerns;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;

trait HasGenerateQuizDraftAction
{
    protected function quizDiscoveryAction(): Action
    {
        return Action::make('quizDiscovery')
            ->label('AI quiz interview')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('AI quiz interview')
            ->modalDescription('Clarify the goal in a chat. When the brief is ready — or you say execute now — the assistant structures the allowlisted brief and can generate an editable quiz draft.')
            ->modalWidth(Width::Screen)
            ->modalContent(fn () => view('filament.actions.quiz-discovery-modal'))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }
}
