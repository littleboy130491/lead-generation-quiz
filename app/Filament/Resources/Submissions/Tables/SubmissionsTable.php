<?php

namespace App\Filament\Resources\Submissions\Tables;

use App\Actions\Analyses\RequestAnalysis;
use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Submissions\ManageSubmission;
use App\Enums\AnalysisStatus;
use App\Enums\DeliveryTrigger;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Submission')->searchable()->toggleable(), TextColumn::make('quiz.name')->label('Quiz')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('status')->badge(), TextColumn::make('first_touch_context.client.browser')->label('First browser')->toggleable(), TextColumn::make('latest_touch_context.client.device')->label('Latest device')->toggleable(), TextColumn::make('quizRevision.version')->label('Revision'), TextColumn::make('analyses_count')->counts('analyses')->label('Analyses'), TextColumn::make('completed_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(collect(SubmissionStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->value])->all()), SelectFilter::make('first_touch_context->client->browser')->label('First browser')->options(['Chrome' => 'Chrome', 'Edge' => 'Edge', 'Firefox' => 'Firefox', 'Safari' => 'Safari', 'Other' => 'Other']), SelectFilter::make('latest_touch_context->client->device')->label('Latest device')->options(['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet']),
        ])->recordActions([
            EditAction::make()->label('View frozen record'),
            Action::make('reanalyze')->requiresConfirmation()->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::Completed)->action(fn (Submission $record) => app(RequestAnalysis::class)->handle($record, auth()->id())),
            Action::make('requestResend')->label('Send latest')->requiresConfirmation()->visible(fn (Submission $record): bool => $record->analyses()->where('status', AnalysisStatus::Completed)->exists())->action(function (Submission $record): void {
                $analysis = $record->analyses()->where('status', AnalysisStatus::Completed)->latest('sequence')->firstOrFail();
                app(RequestReportDelivery::class)->handle($analysis, DeliveryTrigger::Manual, auth()->id());
            }),
            Action::make('markSpam')->label('Mark spam')->requiresConfirmation()->action(fn (Submission $record) => app(ManageSubmission::class)->markSpam($record, auth()->id())),
            Action::make('holdReview')->label('Hold review')->requiresConfirmation()->action(fn (Submission $record) => app(ManageSubmission::class)->hold($record, auth()->id())),
            Action::make('anonymize')->label('Anonymize')->color('danger')->requiresConfirmation()->action(fn (Submission $record) => app(ManageSubmission::class)->anonymize($record, auth()->id())),
            Action::make('export')->label('Export')->action(function (Submission $record) {
                $row = app(ManageSubmission::class)->export($record);

                return response()->streamDownload(function () use ($row): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, array_keys($row));
                    fputcsv($out, $row);
                    fclose($out);
                }, 'submission-'.$record->public_id.'.csv', ['Content-Type' => 'text/csv']);
            }),
        ])->toolbarActions([BulkActionGroup::make([
            BulkAction::make('reanalyze')->requiresConfirmation()->action(fn (Collection $records) => $records->filter(fn (Submission $record) => $record->status === SubmissionStatus::Completed)->each(fn (Submission $record) => app(RequestAnalysis::class)->handle($record, auth()->id()))),
            BulkAction::make('generateAndSend')->label('Generate and send')->requiresConfirmation()->action(fn (Collection $records) => $records->filter(fn (Submission $record) => $record->status === SubmissionStatus::Completed)->each(fn (Submission $record) => app(RequestAnalysis::class)->handle($record, auth()->id(), true))),
            BulkAction::make('resendLatest')->label('Resend latest completed analysis')->requiresConfirmation()->action(fn (Collection $records) => $records->each(function (Submission $record): void {
                $analysis = $record->analyses()->where('status', AnalysisStatus::Completed)->latest('sequence')->first();
                if ($analysis) {
                    app(RequestReportDelivery::class)->handle($analysis, DeliveryTrigger::BulkManual, auth()->id());
                }
            })),
            BulkAction::make('markSpam')->label('Mark spam')->requiresConfirmation()->action(fn (Collection $records) => $records->each(fn (Submission $record) => app(ManageSubmission::class)->markSpam($record, auth()->id()))),
            BulkAction::make('holdReview')->label('Hold for review')->requiresConfirmation()->action(fn (Collection $records) => $records->each(fn (Submission $record) => app(ManageSubmission::class)->hold($record, auth()->id()))),
            BulkAction::make('anonymize')->label('Anonymize')->color('danger')->requiresConfirmation()->action(fn (Collection $records) => $records->each(fn (Submission $record) => app(ManageSubmission::class)->anonymize($record, auth()->id()))),
            BulkAction::make('export')->requiresConfirmation()->action(function (Collection $records) {
                $rows = $records->map(fn (Submission $record) => app(ManageSubmission::class)->export($record))->all();

                return response()->streamDownload(function () use ($rows): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, array_keys($rows[0] ?? []));
                    foreach ($rows as $row) {
                        fputcsv($out, $row);
                    } fclose($out);
                }, 'submissions.csv', ['Content-Type' => 'text/csv']);
            }),
        ])]);
    }
}
