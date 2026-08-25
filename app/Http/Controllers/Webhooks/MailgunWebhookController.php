<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\ReportDelivery;
use Illuminate\Http\Request;

class MailgunWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $signature = $request->input('signature', []);
        $key = config('quiz.mailgun_webhook_signing_key');
        if (! $key || ! is_array($signature) || ! hash_equals(hash_hmac('sha256', ($signature['timestamp'] ?? '').($signature['token'] ?? ''), $key), $signature['signature'] ?? '')) {
            abort(403);
        }

        $event = $request->input('event-data', []);
        $id = $event['message']['headers']['message-id'] ?? null;
        $delivery = ReportDelivery::where('provider_message_id', $id)->first();
        if (! $delivery) {
            return response()->json(['status' => 'ignored']);
        }

        $status = match ($event['event'] ?? '') {
            'accepted' => DeliveryStatus::Accepted,
            'delivered' => DeliveryStatus::Delivered,
            'failed' => DeliveryStatus::Failed,
            'bounced' => DeliveryStatus::Bounced,
            'complained' => DeliveryStatus::Complained,
            default => null,
        };
        if ($status && ! in_array($delivery->status, [DeliveryStatus::Delivered, DeliveryStatus::Bounced, DeliveryStatus::Complained], true)) {
            $delivery->update(['status' => $status, $status === DeliveryStatus::Delivered ? 'delivered_at' : 'failed_at' => now()]);
        }

        return response()->json(['status' => 'ok']);
    }
}
