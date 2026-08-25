<?php

namespace App\Filament\Resources\Submissions\Pages;

use App\Actions\Analyses\ManageAnalysis;
use App\Actions\Analyses\RequestAnalysis;
use App\Actions\Deliveries\ManageDelivery;
use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Submissions\ManageSubmission;
use App\Enums\AnalysisStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSubmission extends EditRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Submission $submission */
        $submission = $this->record;

        return [
            Action::make('requestAnalysis')->label('Request analysis')->requiresConfirmation()->action(fn () => app(RequestAnalysis::class)->handle($submission, auth()->id())),
            Action::make('generateAndSend')->label('Generate and send')->requiresConfirmation()->action(function () use ($submission): void {
                app(RequestAnalysis::class)->handle($submission, auth()->id(), true);
            }),
            Action::make('sendPreferred')->label('Send preferred/latest')->visible(fn () => $submission->analyses()->where('status', AnalysisStatus::Completed)->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $analysis = $submission->preferred_analysis_id ? $submission->analyses()->find($submission->preferred_analysis_id) : $submission->analyses()->where('status', AnalysisStatus::Completed)->latest('sequence')->first();
                if ($analysis) {
                    app(RequestReportDelivery::class)->handle($analysis, DeliveryTrigger::Manual, auth()->id());
                }
            }),
            Action::make('retryLatestAnalysis')->label('Retry latest analysis')->visible(fn () => $submission->analyses()->where('status', AnalysisStatus::Failed)->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $analysis = $submission->analyses()->where('status', AnalysisStatus::Failed)->latest('sequence')->first();
                if ($analysis) {
                    app(ManageAnalysis::class)->retry($analysis, auth()->id());
                }
            }),
            Action::make('cancelLatestAnalysis')->label('Cancel latest analysis')->visible(fn () => $submission->analyses()->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Processing])->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $analysis = $submission->analyses()->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Processing])->latest('sequence')->first();
                if ($analysis) {
                    app(ManageAnalysis::class)->cancel($analysis, auth()->id());
                }
            }),
            Action::make('choosePreferred')->label('Choose latest completed')->visible(fn () => $submission->analyses()->where('status', AnalysisStatus::Completed)->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $analysis = $submission->analyses()->where('status', AnalysisStatus::Completed)->latest('sequence')->first();
                if ($analysis) {
                    app(ManageAnalysis::class)->selectPreferred($analysis, auth()->id());
                }
            }),
            Action::make('retryLatestDelivery')->label('Retry latest delivery')->visible(fn () => $submission->deliveries()->where('status', DeliveryStatus::Failed)->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $delivery = $submission->deliveries()->where('status', DeliveryStatus::Failed)->latest('id')->first();
                if ($delivery) {
                    app(ManageDelivery::class)->retry($delivery, auth()->id());
                }
            }),
            Action::make('cancelLatestDelivery')->label('Cancel latest delivery')->visible(fn () => $submission->deliveries()->where('status', DeliveryStatus::Queued)->exists())->requiresConfirmation()->action(function () use ($submission): void {
                $delivery = $submission->deliveries()->where('status', DeliveryStatus::Queued)->latest('id')->first();
                if ($delivery) {
                    app(ManageDelivery::class)->cancel($delivery, auth()->id());
                }
            }),
            Action::make('markSpam')->label('Mark spam')->requiresConfirmation()->action(fn () => app(ManageSubmission::class)->markSpam($submission, auth()->id())),
            Action::make('holdReview')->label('Hold for review')->requiresConfirmation()->action(fn () => app(ManageSubmission::class)->hold($submission, auth()->id())),
            Action::make('anonymize')->label('Anonymize')->color('danger')->requiresConfirmation()->action(fn () => app(ManageSubmission::class)->anonymize($submission, auth()->id())),
        ];
    }
}
