<?php

namespace App\Ai\Discovery;

class RuleBasedQuizDiscoveryInterviewer implements QuizDiscoveryInterviewer
{
    public function respond(array $brief, array $messages, string $systemPrompt): array
    {
        $field = QuizDiscoveryBrief::nextMissingField($brief);
        $questions = [
            'business_context' => 'What is the business, offer, or situation this quiz should focus on?',
            'target_audience' => 'Who is the target audience for this quiz?',
            'objective' => 'What should this quiz help the business achieve?',
            'desired_insight' => 'What useful insight or next step should respondents receive at the end?',
        ];

        return [
            'brief' => $brief,
            'message' => $field === null
                ? 'Thanks — I have the core context. Please review the brief below, adjust anything needed, then generate the quiz draft.'
                : $questions[$field],
        ];
    }
}
