<?php

namespace App\Jobs;

use App\Actions\Deliveries\RequestReportDelivery;
use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Data\ReportSchema;
use App\Ai\GenerationException;
use App\Enums\AnalysisStatus;
use App\Enums\DeliveryTrigger;
use App\Models\Analysis;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $analysisId, public ?string $lease = null) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds'));
    }

    public function handle(QuizAnalysisGenerator $generator, RequestReportDelivery $deliveries, ?SubmissionEventRecorder $events = null): void
    {
        $events ??= app(SubmissionEventRecorder::class);
        $lease = $this->lease ?? (string) Str::uuid();
        $now = now();
        if ($this->lease === null) {
            $claimed = Analysis::query()->whereKey($this->analysisId)->where('status', AnalysisStatus::Queued)->update([
                'status' => AnalysisStatus::Processing,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds')),
                'execution_lease' => $lease,
                'execution_generation' => DB::raw('execution_generation + 1'),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => $now,
            ]);
        } else {
            $claimed = Analysis::query()->whereKey($this->analysisId)->where('status', AnalysisStatus::Processing)->where('execution_lease', $lease)->update(['updated_at' => $now]);
        }
        if ($claimed !== 1) {
            return;
        }

        $analysis = Analysis::findOrFail($this->analysisId);
        $fence = $analysis->execution_generation;
        if (! $this->renew($lease, $fence)) {
            return;
        }
        try {
            $result = $generator->generate($analysis->input_snapshot['revision'] ?? [], $analysis->input_snapshot['answers'] ?? [], $analysis->requested_provider_chain ?? [], (string) $analysis->system_prompt_snapshot);
            $report = ReportSchema::validate($result['result']);
            if ($this->updateOwned($lease, $fence, [
                'status' => AnalysisStatus::Completed, 'structured_result' => $report, 'actual_provider' => $result['provider'], 'actual_model' => $result['model'], 'provider_attempts' => $result['attempts'], 'failover_occurred' => count($result['attempts']) > 1, 'completed_at' => now(), 'heartbeat_at' => now(), 'lease_expires_at' => null, 'error_code' => null, 'error_message' => null,
            ])) {
                $completed = $analysis->fresh();
                $events->record($completed->submission, 'analysis_completed', ['analysis_id' => $analysis->id]);
                if ($analysis->trigger->value === 'automatic') {
                    $deliveries->handle($completed, DeliveryTrigger::Automatic);
                } elseif (($analysis->input_snapshot['send_when_completed'] ?? false) === true) {
                    $deliveries->handle($completed, DeliveryTrigger::Manual, $analysis->requested_by);
                }
            }
        } catch (GenerationException $e) {
            if ($this->updateOwned($lease, $fence, ['status' => AnalysisStatus::Failed, 'provider_attempts' => $e->attempts, 'error_code' => $e->codeName, 'error_message' => $e->getMessage(), 'heartbeat_at' => now(), 'lease_expires_at' => null])) {
                $events->record($analysis->submission, 'analysis_failed', ['analysis_id' => $analysis->id, 'code' => $e->codeName]);
            }
        } catch (\Throwable $e) {
            if ($this->updateOwned($lease, $fence, ['status' => AnalysisStatus::Failed, 'error_code' => 'generation_unexpected', 'error_message' => str($e->getMessage())->limit(500)->toString(), 'heartbeat_at' => now(), 'lease_expires_at' => null])) {
                $events->record($analysis->submission, 'analysis_failed', ['analysis_id' => $analysis->id, 'code' => 'generation_unexpected']);
            }
        }
    }

    private function renew(string $lease, int $fence): bool
    {
        return Analysis::query()->whereKey($this->analysisId)->where('status', AnalysisStatus::Processing)->where('execution_lease', $lease)->where('execution_generation', $fence)->update(['heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds')), 'updated_at' => now()]) === 1;
    }

    private function updateOwned(string $lease, int $fence, array $attributes): bool
    {
        $attributes['updated_at'] = now();

        return Analysis::query()->whereKey($this->analysisId)->where('status', AnalysisStatus::Processing)->where('execution_lease', $lease)->where('execution_generation', $fence)->update($attributes) === 1;
    }
}
