<?php

namespace App\Filament\Resources\Quizzes\Tables;

use App\Actions\Quizzes\DuplicateQuiz;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Models\Quiz;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class QuizzesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('activeRevision.version')->label('Active revision'),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([TrashedFilter::make()])->headerActions([
            ExportAction::make('exportFiltered')->label('Export filtered')->exports([ExcelExport::make()->fromTable()->askForFilename()->askForWriterType()]),
        ])->recordActions([
            EditAction::make(),
            Action::make('previewDraft')->label('Preview draft')->url(fn (Quiz $record): string => route('admin.quizzes.preview', $record)),
            Action::make('revisionHistory')->label('Revision history')->url(fn (Quiz $record): string => route('admin.quizzes.history', $record)),
            Action::make('publish')->requiresConfirmation()->action(fn (Quiz $record) => app(PublishQuizRevision::class)->handle($record, auth()->id())),
            Action::make('duplicate')->label('Duplicate draft')->requiresConfirmation()->action(fn (Quiz $record) => app(DuplicateQuiz::class)->handle($record)),
        ])->toolbarActions([BulkActionGroup::make([
            ExportBulkAction::make('exportSelected')->label('Export selected')->exports([ExcelExport::make()->fromTable()->askForFilename()->askForWriterType()]),
            DeleteBulkAction::make(),
        ])]);
    }
}
