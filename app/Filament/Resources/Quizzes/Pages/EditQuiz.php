<?php

namespace App\Filament\Resources\Quizzes\Pages;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Filament\Resources\Quizzes\Concerns\HasGenerateQuizDraftAction;
use App\Filament\Resources\Quizzes\QuizResource;
use App\Filament\Resources\Quizzes\Schemas\QuizForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditQuiz extends EditRecord
{
    use HasGenerateQuizDraftAction;

    protected static string $resource = QuizResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return QuizForm::toFormState($this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['draft_definition'] = QuizForm::toDefinition($data);
        unset($data['builder_blocks']);

        if (filled($data['password'] ?? null)) {
            $data['password_hash'] = QuizForm::passwordForStorage($data['password']);
        }
        unset($data['password']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->url(fn (): string => route('quizzes.show', $this->getRecord()), shouldOpenInNewTab: true),
            $this->generateQuizDraftAction(),
            Action::make('publish')->requiresConfirmation()->action(fn () => app(PublishQuizRevision::class)->handle($this->getRecord(), auth()->id())),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
