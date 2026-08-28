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
        $this->assertTrue(QuizDiscoveryBrief::hasEnoughContext($brief));
        $this->assertFalse(QuizDiscoveryBrief::isReady($brief));
    }

    public function test_opening_context_alone_is_enough_to_generate_on_execute_now(): void
    {
        $brief = QuizDiscoveryBrief::merge([], [
            'business_context' => 'A consulting quiz for operations leaders',
        ]);

        $this->assertTrue(QuizDiscoveryBrief::hasEnoughContext($brief));
        $this->assertFalse(QuizDiscoveryBrief::isReady($brief));
        $this->assertFalse(QuizDiscoveryBrief::hasEnoughContext([]));
    }

    public function test_an_unspecified_question_count_is_not_converted_into_one_question(): void
    {
        $brief = QuizDiscoveryBrief::merge([], [
            'business_context' => 'A nutrition coaching quiz',
            'question_count' => 0,
        ]);

        $this->assertArrayNotHasKey('question_count', $brief);
    }

    public function test_a_valid_explicit_question_count_is_preserved(): void
    {
        $brief = QuizDiscoveryBrief::merge([], ['question_count' => 8]);

        $this->assertSame(8, $brief['question_count']);
    }
}
