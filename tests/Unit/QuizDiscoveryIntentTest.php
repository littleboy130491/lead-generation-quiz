<?php

namespace Tests\Unit;

use App\Ai\Discovery\QuizDiscoveryIntent;
use Tests\TestCase;

class QuizDiscoveryIntentTest extends TestCase
{
    public function test_it_recognizes_explicit_generate_now_commands(): void
    {
        foreach ([
            'execute now',
            'Please generate the quiz',
            'create the quiz now.',
            "that's enough",
            'go ahead',
        ] as $message) {
            $this->assertTrue(QuizDiscoveryIntent::wantsImmediateGeneration($message), $message);
        }
    }

    public function test_it_does_not_treat_ordinary_interview_answers_as_generate_commands(): void
    {
        foreach ([
            'Owners who are executives now considering a change',
            'Generate leads for our coaching offer',
            'I am done guessing and want a clear next step for respondents',
        ] as $message) {
            $this->assertFalse(QuizDiscoveryIntent::wantsImmediateGeneration($message), $message);
        }
    }
}
