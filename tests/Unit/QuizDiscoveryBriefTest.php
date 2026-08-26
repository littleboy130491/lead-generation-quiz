<?php

namespace Tests\Unit;

use App\Ai\Discovery\QuizDiscoveryBrief;
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
}
