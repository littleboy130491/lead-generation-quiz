<?php

namespace App\Ai\Discovery;

class RuleBasedQuizDiscoveryInterviewer implements QuizDiscoveryInterviewer
{
    public function respond(array $brief, array $messages, string $systemPrompt): array
    {
        $field = QuizDiscoveryBrief::nextMissingField($brief);
        $questions = [
            'objective' => 'Before we get into the details, what outcome do you want this quiz to create—for the people taking it and for your brand?',
            'target_audience' => 'Who is the ideal person taking this quiz? Describe their current situation, biggest frustrations or problems, and the progress or dreams they want to reach.',
            'business_context' => 'What product, service, or brand experience can you genuinely offer to help them?',
            'desired_insight' => 'At the end, what clarity, useful information, recommendation, or next step should they receive—and how should that helpful experience begin building trust in your brand?',
        ];

        return [
            'brief' => $brief,
            'message' => $field === null
                ? 'Thanks — I have the core context. Please review the brief below, adjust anything needed, then generate the quiz draft.'
                : $questions[$field],
        ];
    }
}
