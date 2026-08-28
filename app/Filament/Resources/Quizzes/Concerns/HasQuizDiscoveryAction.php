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
        return $this->quizAiChatAction(
            name: 'quizDiscovery',
            label: 'AI quiz interview',
            heading: 'AI quiz interview',
            description: 'Chat to clarify the quiz, then say create the quiz now—or use Create quiz now—to generate an editable draft.',
            mode: 'create',
        );
    }

    protected function quizAiEditAction(): Action
    {
        return $this->quizAiChatAction(
            name: 'editWithAi',
            label: 'Edit with AI',
            heading: 'Edit existing quiz with AI',
            description: 'The assistant reviews the existing quiz with you, recommends changes, and replaces the editable draft only after you choose Update quiz.',
            mode: 'edit',
        );
    }

    private function quizAiChatAction(
        string $name,
        string $label,
        string $heading,
        string $description,
        string $mode,
    ): Action {
        $available = $this->quizDiscoveryProviderAvailable();

        return Action::make($name)
            ->disabled(! $available)
            ->tooltip($available ? null : 'Add a Quiz AI provider chain with matching credentials in Operational settings to start an interview.')
            ->label($label)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalWidth(Width::Screen)
            ->modalContent(fn () => view('filament.actions.quiz-discovery-modal', [
                'quizId' => $this->quizDiscoveryQuizId(),
                'mode' => $mode,
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
