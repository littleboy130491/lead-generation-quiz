<?php

namespace App\Ai\Discovery;

class RuleBasedQuizDiscoveryInterviewer implements QuizDiscoveryInterviewer
{
    public function respond(array $brief, array $messages, string $systemPrompt): array
    {
        $latestUserMessage = $this->latestUserMessage($messages);
        $executeNow = QuizDiscoveryBrief::wantsToExecute($latestUserMessage);
        $field = QuizDiscoveryBrief::nextMissingField($brief);
        $questions = [
            'objective' => 'Before we get into the details, what outcome do you want this quiz to create—for the people taking it and for your brand?',
            'target_audience' => 'Who is the ideal person taking this quiz? Describe their current situation, biggest frustrations or problems, and the progress or dreams they want to reach.',
            'business_context' => 'What product, service, or brand experience can you genuinely offer to help them?',
            'desired_insight' => 'At the end, what clarity, useful information, recommendation, or next step should they receive—and how should that helpful experience begin building trust in your brand?',
        ];

        if ($field === null || $executeNow) {
            return [
                'brief' => $brief,
                'ready_to_generate' => QuizDiscoveryBrief::isReady($brief),
                'message' => QuizDiscoveryBrief::isReady($brief)
                    ? 'Thanks — I have the core context. Please review the structured brief below, adjust anything needed, then create the quiz draft. You can also say "execute now" anytime you are ready.'
                    : 'I can generate the draft once the four core brief fields are complete. Please answer the remaining question, or add the missing details in Review brief.',
            ];
        }

        return [
            'brief' => $brief,
            'ready_to_generate' => false,
            'message' => $questions[$field],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function latestUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? '') === 'user') {
                return (string) ($messages[$index]['content'] ?? '');
            }
        }

        return '';
    }
}
