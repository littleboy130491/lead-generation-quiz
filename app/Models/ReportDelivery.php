<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReportDelivery extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            $dirty = array_keys($delivery->getDirty());
            $immutable = ['analysis_id', 'submission_id', 'recipient_email', 'trigger', 'sent_manually', 'requested_by', 'template_identifier', 'template_version', 'subject_snapshot', 'html_snapshot', 'text_snapshot'];
            if (array_intersect($dirty, $immutable)) {
                throw new LogicException('Report delivery content and request history are immutable.');
            }

            $from = $delivery->getOriginal('status');
            if (in_array($from, [DeliveryStatus::Delivered->value, DeliveryStatus::Bounced->value, DeliveryStatus::Complained->value], true)) {
                throw new LogicException('Completed report deliveries are immutable append-only history.');
            }

            if ($from === DeliveryStatus::Accepted->value && ! $delivery->isAllowedAcceptedWebhookTransition($dirty)) {
                throw new LogicException('Accepted report deliveries permit only a terminal webhook lifecycle transition.');
            }
        });

        static::deleting(function (self $delivery): void {
            if (in_array($delivery->status, [DeliveryStatus::Accepted, DeliveryStatus::Delivered, DeliveryStatus::Bounced, DeliveryStatus::Complained], true)) {
                throw new LogicException('Completed report deliveries are append-only history.');
            }
        });
    }

    /** @param list<string> $dirty */
    private function isAllowedAcceptedWebhookTransition(array $dirty): bool
    {
        $allowedFields = ['status', 'delivered_at', 'failed_at', 'updated_at'];
        $to = $this->status instanceof DeliveryStatus ? $this->status : DeliveryStatus::from((string) $this->status);

        return ! array_diff($dirty, $allowedFields)
            && in_array($to, [DeliveryStatus::Accepted, DeliveryStatus::Delivered, DeliveryStatus::Bounced, DeliveryStatus::Complained, DeliveryStatus::Failed], true);
    }

    protected function casts(): array
    {
        return ['status' => DeliveryStatus::class, 'trigger' => DeliveryTrigger::class, 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime', 'attempt_count' => 'integer', 'recovery_count' => 'integer', 'execution_generation' => 'integer', 'lease_expires_at' => 'datetime'];
    }

    public function analysis()
    {
        return $this->belongsTo(Analysis::class);
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }
}
