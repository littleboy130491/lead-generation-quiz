<?php

namespace App\Filament\Resources\Quizzes\Pages;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Filament\Resources\Quizzes\QuizResource;
use App\Filament\Resources\Quizzes\Schemas\QuizForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;

class EditQuiz extends EditRecord
{
    protected static string $resource = QuizResource::class;

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
            Action::make('generateDraft')
                ->label('Generate AI draft')
                ->form([
                    Textarea::make('business_context')->required()->maxLength(4000),
                    TextInput::make('target_audience')->maxLength(500),
                    TextInput::make('objective')->maxLength(500),
                    TextInput::make('desired_insight')->maxLength(500),
                    TextInput::make('question_count')->numeric()->minValue(1)->maxValue(30),
                    TextInput::make('tone')->maxLength(100),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => app(GenerateQuizDraft::class)->handle($this->getRecord(), $data)),
            Action::make('publish')->requiresConfirmation()->action(fn () => app(PublishQuizRevision::class)->handle($this->getRecord(), auth()->id())),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
