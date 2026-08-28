<?php

namespace App\Filament\Resources\Quizzes\Pages;

use App\Filament\Resources\Quizzes\Concerns\HasQuizDiscoveryAction;
use App\Filament\Resources\Quizzes\QuizResource;
use App\Filament\Resources\Quizzes\Schemas\QuizForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateQuiz extends CreateRecord
{
    use HasQuizDiscoveryAction;

    protected static string $resource = QuizResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return QuizForm::toPersistenceData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->quizDiscoveryAction(),
        ];
    }
}
