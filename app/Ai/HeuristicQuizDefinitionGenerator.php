<?php

namespace App\Ai;

/**
 * Builds a validated schema-version-1 quiz draft from a sanitized brief when no
 * quiz AI provider credentials are usable. Brief fields are plain text only.
 */
class HeuristicQuizDefinitionGenerator
{
    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    public function generate(array $brief): array
    {
        $count = max(1, min(30, (int) ($brief['question_count'] ?? 6)));
        $context = $this->plain($brief['business_context'] ?? 'this offering', 400);
        $audience = $this->plain($brief['target_audience'] ?? 'your audience', 200);
        $objective = $this->plain($brief['objective'] ?? 'assess readiness', 200);
        $insight = $this->plain($brief['desired_insight'] ?? 'the most useful next step', 200);
        $tone = $this->plain($brief['tone'] ?? 'clear and practical', 100);

        $blocks = [];
        $templates = $this->questionTemplates($audience, $objective, $insight, $tone);

        for ($i = 0; $i < $count; $i++) {
            if ($i > 0 && $i % 3 === 0) {
                $blocks[] = [
                    'id' => 'page_'.(int) ($i / 3),
                    'type' => 'page_break',
                ];
            }

            $template = $templates[$i % count($templates)];
            $blocks[] = [
                'id' => 'q'.($i + 1),
                'type' => 'question',
                ...$template,
                'required' => true,
            ];
        }

        return [
            'schema_version' => 1,
            'opening' => [
                'html' => '<h1>'.e($objective).'</h1><p>'.e($context).'</p><p>Built for '.e($audience).'.</p>',
                'start_button_label' => 'Start',
                'hide_start_button' => false,
            ],
            'result' => ['mode' => 'ai'],
            'blocks' => $blocks,
        ];
    }

    /**
     * @return list<array{question_type: string, label: string, options?: list<array{id: string, value: string, label: string}>}>
     */
    private function questionTemplates(string $audience, string $objective, string $insight, string $tone): array
    {
        return [
            [
                'question_type' => 'yes_no',
                'label' => 'Are you actively working toward '.$objective.'?',
            ],
            [
                'question_type' => 'single_choice',
                'label' => 'Which best describes where '.$audience.' are today?',
                'options' => [
                    ['id' => 'early', 'value' => 'early', 'label' => 'Just getting started'],
                    ['id' => 'progressing', 'value' => 'progressing', 'label' => 'Making progress with gaps'],
                    ['id' => 'advanced', 'value' => 'advanced', 'label' => 'Mostly established'],
                ],
            ],
            [
                'question_type' => 'multiple_choice',
                'label' => 'What challenges are most relevant right now?',
                'options' => [
                    ['id' => 'clarity', 'value' => 'clarity', 'label' => 'Unclear priorities'],
                    ['id' => 'capacity', 'value' => 'capacity', 'label' => 'Limited capacity'],
                    ['id' => 'process', 'value' => 'process', 'label' => 'Inconsistent process'],
                    ['id' => 'results', 'value' => 'results', 'label' => 'Hard to measure results'],
                ],
            ],
            [
                'question_type' => 'short_text',
                'label' => 'In one sentence, what does success look like for '.$insight.'?',
                'max_length' => 200,
            ],
            [
                'question_type' => 'yes_no',
                'label' => 'Do you already have a '.$tone.' plan to reach '.$objective.'?',
            ],
            [
                'question_type' => 'long_text',
                'label' => 'What else should we know about your situation?',
                'max_length' => 1000,
            ],
        ];
    }

    private function plain(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        $text = preg_replace('/<\?(?:php|=)?|\{\{\s*|javascript\s*:/iu', '', $text) ?? '';
        $text = trim($text);

        if ($text === '') {
            return 'this topic';
        }

        return mb_substr($text, 0, $max);
    }
}
