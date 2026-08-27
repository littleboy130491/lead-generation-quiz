<?php

namespace App\Filament\Resources\Quizzes\Concerns;

use App\Ai\ConfiguredAiProviders;
use App\Settings\ApplicationSettings;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;

trait HasQuizDiscoveryAction
{
    protected function quizDiscoveryAction(): Action
    {
        $available = $this->quizDiscoveryProviderAvailable();

        return Action::make('quizDiscovery')
            ->disabled(! $available)
            ->tooltip($available ? null : 'Add a Quiz AI provider chain with matching credentials in Operational settings to start an interview.')
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

    protected function quizDiscoveryProviderAvailable(): bool
    {
        return app(ConfiguredAiProviders::class)->hasUsableCredentials(
            (array) app(ApplicationSettings::class)->get('ai.quiz'),
        );
    }

    protected function quizDiscoveryQuizId(): ?int
    {
        return null;
    }
}
