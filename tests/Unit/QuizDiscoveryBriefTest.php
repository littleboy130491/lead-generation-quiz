<?php

namespace Tests\Unit;

use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
use Tests\TestCase;

class QuizDiscoveryBriefTest extends TestCase
{
    public function test_it_normalizes_an_incomplete_brief_and_identifies_the_next_missing_field(): void
    {
        $brief = QuizDiscoveryBrief::merge([], [
            'business_context' => '<b>Leadership coaching</b> for independent consultants',
            'objective' => 'Identify their current business bottleneck',
            'unexpected' => 'must not persist',
        ]);

        $this->assertSame('Leadership coaching for independent consultants', $brief['business_context']);
        $this->assertSame('Identify their current business bottleneck', $brief['objective']);
        $this->assertArrayNotHasKey('unexpected', $brief);
        $this->assertSame('target_audience', QuizDiscoveryBrief::nextMissingField($brief));
    }

    public function test_execute_now_phrases_are_detected_as_control_intent(): void
    {
        $this->assertTrue(QuizDiscoveryIntent::wantsExecute('execute now'));
        $this->assertTrue(QuizDiscoveryIntent::wantsExecute('Please generate the draft'));
        $this->assertTrue(QuizDiscoveryIntent::isControl('create the quiz'));
        $this->assertFalse(QuizDiscoveryIntent::wantsExecute('I want founders to execute a weekly ops review'));
    }
}
