<?php

namespace Tests\Unit;

use App\Services\SubmissionContext;
use Tests\TestCase;

class SubmissionContextTest extends TestCase
{
    public function test_query_context_keeps_only_documented_attribution_keys_and_drops_nested_pii_answers_and_secrets(): void
    {
        $query = [
            'utm_source' => 'google',
            'utm_campaign' => 'launch',
            'gclid' => 'gclid-123',
            'campaign_id' => '42',
            'email' => 'lead@example.test',
            'answers' => ['q1' => 'private answer'],
            'form' => ['contact' => ['phone' => '+15551234567'], 'utm_source' => 'nested-google'],
            'context' => ['lead' => ['company' => 'Acme'], 'cookie' => 'session-value'],
            'unknown' => 'do-not-store',
        ];

        $sanitized = app(SubmissionContext::class)->sanitizeQuery($query);

        $this->assertSame([
            'utm_source' => 'google',
            'utm_campaign' => 'launch',
            'gclid' => 'gclid-123',
            'campaign_id' => '42',
        ], $sanitized);
        $this->assertStringNotContainsString('lead@example.test', json_encode($sanitized, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private answer', json_encode($sanitized, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('session-value', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }
}
