<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Analysis extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $analysis): void {
            $dirty = array_keys($analysis->getDirty());
            if ($analysis->getOriginal('completed_at') !== null || $analysis->getOriginal('status') === AnalysisStatus::Completed->value) {
                throw new LogicException('Completed analyses are immutable append-only history.');
            }
            if (array_intersect($dirty, ['public_id', 'submission_id', 'sequence', 'trigger', 'created_manually', 'requested_by', 'requested_provider_chain', 'prompt_version', 'system_prompt_snapshot', 'input_snapshot', 'structured_result', 'rendered_report'])) {
                throw new LogicException('Analysis request snapshots and results are immutable.');
            }
        });

        static::deleting(function (self $analysis): void {
            if ($analysis->completed_at !== null || $analysis->status === AnalysisStatus::Completed) {
                throw new LogicException('Completed analyses are append-only history.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => AnalysisStatus::class, 'trigger' => AnalysisTrigger::class, 'requested_provider_chain' => 'array', 'provider_attempts' => 'array', 'input_snapshot' => 'array', 'structured_result' => 'array', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'attempt_count' => 'integer', 'recovery_count' => 'integer', 'execution_generation' => 'integer', 'lease_expires_at' => 'datetime'];
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ReportDelivery::class);
    }
}
