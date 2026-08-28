<?php

namespace App\Ai\Discovery;

class RuleBasedQuizDiscoveryInterviewer implements QuizDiscoveryInterviewer
{
    public function respond(array $brief, array $messages, string $systemPrompt): array
    {
        $lastUser = $this->lastUserMessage($messages);
        $questions = [
            'business_context' => 'What product, service, or brand experience can you genuinely offer to help them?',
            'objective' => 'Before we get into the details, what outcome do you want this quiz to create—for the people taking it and for your brand?',
            'target_audience' => 'Who is the ideal person taking this quiz? Describe their current situation, biggest frustrations or problems, and the progress or dreams they want to reach.',
            'desired_insight' => 'At the end, what clarity, useful information, recommendation, or next step should they receive—and how should that helpful experience begin building trust in your brand?',
        ];

        if (QuizDiscoveryIntent::wantsImmediateGeneration($lastUser)) {
            if (QuizDiscoveryBrief::hasEnoughContext($brief)) {
                return [
                    'brief' => $brief,
                    'message' => QuizDiscoveryPrompt::GENERATION_REQUESTED_MESSAGE,
                    'action' => 'generate',
                ];
            }

            $field = QuizDiscoveryBrief::nextMissingField($brief) ?? 'business_context';

            return [
                'brief' => $brief,
                'message' => 'Tell me a bit more about the quiz you want before I create it. '.$questions[$field],
                'action' => 'continue',
            ];
        }

        $field = QuizDiscoveryBrief::nextMissingField($brief);
        if ($field !== null && $lastUser !== '') {
            $brief = QuizDiscoveryBrief::merge($brief, [$field => $lastUser]);
            $field = QuizDiscoveryBrief::nextMissingField($brief);
        }

        if ($field === null) {
            return [
                'brief' => $brief,
                'message' => QuizDiscoveryPrompt::READY_MESSAGE,
                'action' => 'generate',
            ];
        }

        return [
            'brief' => $brief,
            'message' => $questions[$field],
            'action' => 'continue',
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function lastUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$index]['content'] ?? ''));
            }
        }

        return '';
    }
}
