<?php

namespace App\Ai\Discovery;

class RuleBasedQuizDiscoveryInterviewer implements QuizDiscoveryInterviewer
{
    public function respond(array $brief, array $messages, string $systemPrompt): array
    {
        $latestUser = $this->latestUserMessage($messages);
        $wantsExecute = QuizDiscoveryIntent::wantsExecute($latestUser);
        $field = QuizDiscoveryBrief::nextMissingField($brief);
        $ready = QuizDiscoveryBrief::isReady($brief);

        if ($wantsExecute && $ready) {
            return [
                'brief' => $brief,
                'action' => QuizDiscoveryAction::Execute,
                'message' => 'Understood — generating the quiz draft from the structured brief now.',
            ];
        }

        if ($wantsExecute && ! $ready) {
            $label = str_replace('_', ' ', (string) $field);

            return [
                'brief' => $brief,
                'action' => QuizDiscoveryAction::Continue,
                'message' => "I still need the {$label} before I can execute. {$this->questionFor($field)}",
            ];
        }

        if ($ready) {
            return [
                'brief' => $brief,
                'action' => QuizDiscoveryAction::Ready,
                'message' => 'Thanks — I have the core context structured into the quiz brief. Review it below, adjust anything needed, then generate the draft or say "execute now".',
            ];
        }

        return [
            'brief' => $brief,
            'action' => QuizDiscoveryAction::Continue,
            'message' => $this->questionFor($field),
        ];
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    private function latestUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                return (string) ($messages[$index]['content'] ?? '');
            }
        }

        return '';
    }

    private function questionFor(?string $field): string
    {
        return match ($field) {
            'objective' => 'Before we get into the details, what outcome do you want this quiz to create—for the people taking it and for your brand?',
            'target_audience' => 'Who is the ideal person taking this quiz? Describe their current situation, biggest frustrations or problems, and the progress or dreams they want to reach.',
            'business_context' => 'What product, service, or brand experience can you genuinely offer to help them?',
            'desired_insight' => 'At the end, what clarity, useful information, recommendation, or next step should they receive—and how should that helpful experience begin building trust in your brand?',
            default => 'Tell me a bit more about the quiz you want to create.',
        };
    }
}
