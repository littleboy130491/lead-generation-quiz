<?php

namespace App\Filament\Resources\Quizzes\Tables;

use App\Actions\Quizzes\DuplicateQuiz;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Models\Quiz;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class QuizzesTable
{
    public static function configure(Table $table): Table
    {
        return $table->filters([TrashedFilter::make()])->recordActions([
            EditAction::make(),
            Action::make('previewDraft')->label('Preview draft')->url(fn (Quiz $record): string => route('admin.quizzes.preview', $record)),
            Action::make('revisionHistory')->label('Revision history')->url(fn (Quiz $record): string => route('admin.quizzes.history', $record)),
            Action::make('publish')->requiresConfirmation()->action(fn (Quiz $record) => app(PublishQuizRevision::class)->handle($record, auth()->id())),
            Action::make('duplicate')->label('Duplicate draft')->requiresConfirmation()->action(fn (Quiz $record) => app(DuplicateQuiz::class)->handle($record)),
        ])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
