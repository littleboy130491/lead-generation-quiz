<?php

namespace App\Filament\Resources\Submissions\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Frozen submission')->columns(1)->schema([
                TextInput::make('email')->disabled(),
                TextInput::make('status')->disabled(),
                TextInput::make('quiz.name')->label('Quiz')->disabled(),
                TextInput::make('quizRevision.version')->label('Frozen revision')->disabled(),
                Textarea::make('frozen_questions_and_answers')
                    ->label('Frozen questions & answers')
                    ->formatStateUsing(function ($record): string {
                        $definition = $record?->quizRevision?->definition ?? [];
                        $blocks = $definition['blocks'] ?? [];
                        $answers = $record?->answers_snapshot ?? [];

                        $out = [];

                        $fmtText = static function (mixed $value, int $max = 1800): string {
                            if (is_string($value)) {
                                $text = $value;
                            } elseif (is_scalar($value)) {
                                $text = (string) $value;
                            } else {
                                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                                $text = is_string($encoded) ? $encoded : '';
                            }
                            $text = trim(preg_replace('/[\r\n]+/u', ' ', (string) $text));
                            if ($text === '') {
                                return '—';
                            }

                            if (mb_strlen($text) > $max) {
                                $text = mb_substr($text, 0, $max).'...';
                            }

                            return $text;
                        };

                        foreach ($blocks as $block) {
                            if (($block['type'] ?? null) !== 'question') {
                                continue;
                            }

                            $id = $block['id'] ?? null;
                            if (! is_string($id) || $id === '') {
                                continue;
                            }

                            $label = $block['label'] ?? $id;
                            if (! is_string($label) || trim($label) === '') {
                                $label = $id;
                            }

                            $label = trim(preg_replace('/[\r\n]+/u', ' ', (string) $label));
                            $questionType = $block['question_type'] ?? 'question';

                            $answerText = '—';

                            if ($questionType === 'yes_no') {
                                $val = $answers[$id] ?? null;
                                if (is_string($val)) {
                                    $answerText = match (strtolower($val)) {
                                        'yes' => 'Yes',
                                        'no' => 'No',
                                        default => $fmtText($val),
                                    };
                                }
                            } elseif ($questionType === 'single_choice') {
                                $val = $answers[$id] ?? null;

                                $options = $block['options'] ?? [];
                                $labelByValue = [];
                                foreach ($options as $opt) {
                                    $optValue = $opt['value'] ?? $opt['id'] ?? null;
                                    if (! is_string($optValue) || $optValue === '') {
                                        continue;
                                    }
                                    $labelByValue[$optValue] = is_string($opt['label'] ?? null) && trim((string) $opt['label']) !== ''
                                        ? (string) $opt['label']
                                        : $optValue;
                                }

                                if (is_string($val) && isset($labelByValue[$val])) {
                                    $answerText = $labelByValue[$val];
                                } else {
                                    $answerText = $fmtText($val);
                                }
                            } elseif ($questionType === 'multiple_choice') {
                                $vals = $answers[$id] ?? [];
                                if (! is_array($vals)) {
                                    $vals = [];
                                }

                                $options = $block['options'] ?? [];
                                $labelByValue = [];
                                foreach ($options as $opt) {
                                    $optValue = $opt['value'] ?? $opt['id'] ?? null;
                                    if (! is_string($optValue) || $optValue === '') {
                                        continue;
                                    }
                                    $labelByValue[$optValue] = is_string($opt['label'] ?? null) && trim((string) $opt['label']) !== ''
                                        ? (string) $opt['label']
                                        : $optValue;
                                }

                                $labels = [];
                                foreach ($vals as $val) {
                                    $key = is_string($val) ? $val : (is_scalar($val) ? (string) $val : null);
                                    if ($key === null || $key === '') {
                                        continue;
                                    }

                                    $labels[] = $labelByValue[$key] ?? $key;
                                }

                                $answerText = $labels !== []
                                    ? implode(', ', array_map(fn (mixed $l) => $fmtText($l), $labels))
                                    : '—';
                            } elseif ($questionType === 'short_text' || $questionType === 'long_text') {
                                $answerText = $fmtText($answers[$id] ?? null);
                            } else {
                                // If a question type isn't recognized here, still show something readable.
                                $answerText = $fmtText($answers[$id] ?? null);
                            }

                            $out[] = sprintf(
                                "%s\n  (%s)\n  Answer: %s",
                                $label,
                                is_string($questionType) ? $questionType : 'question',
                                $answerText
                            );
                        }

                        return $out !== [] ? implode("\n\n", $out) : 'No frozen questions found for this submission.';
                    })
                    ->disabled()
                    ->rows(16)
                    ->columnSpanFull(),
            ]),

            Section::make('Attribution and request context')->columns(1)->schema([
                Textarea::make('first_touch_context')
                    ->label('First touch (immutable)')
                    ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
                    ->disabled()
                    ->rows(9)
                    ->columnSpanFull(),
                Textarea::make('latest_touch_context')
                    ->label('Latest touch')
                    ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
                    ->disabled()
                    ->rows(9)
                    ->columnSpanFull(),
            ]),

            Section::make('Event timeline')->columns(1)->schema([
                Textarea::make('event_timeline')
                    ->formatStateUsing(function ($record): string {
                        return $record?->events()->get()->map(fn ($event) => sprintf(
                            '%s — %s%s',
                            $event->created_at?->toDateTimeString(),
                            $event->event,
                            $event->details === [] ? '' : ' '.json_encode($event->details, JSON_UNESCAPED_SLASHES)
                        ))->implode("\n") ?: 'No events yet.';
                    })
                    ->disabled()
                    ->rows(12)
                    ->columnSpanFull(),
            ]),

            Section::make('Analysis and delivery history')->columns(1)->schema([
                Textarea::make('analysis_history')
                    ->formatStateUsing(function ($record): string {
                        return $record?->analyses()
                            ->with('deliveries')
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn ($analysis) => sprintf(
                                '#%s %s (%s) — deliveries: %s',
                                $analysis->sequence,
                                $analysis->status->value,
                                $analysis->trigger->value,
                                $analysis->deliveries->map(fn ($delivery) => $delivery->status->value)->implode(', ') ?: 'none'
                            ))->implode("\n") ?: 'No analyses yet.';
                    })
                    ->disabled()
                    ->rows(8)
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
