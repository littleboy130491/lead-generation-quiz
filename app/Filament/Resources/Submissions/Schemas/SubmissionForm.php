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
        return $schema->components([
            Section::make('Frozen submission')->schema([
                TextInput::make('email')->disabled(),
                TextInput::make('status')->disabled(),
                TextInput::make('quiz_revision_id')->label('Frozen revision')->disabled(),
                Textarea::make('answers_snapshot')->label('Frozen answers')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')->disabled()->rows(12)->columnSpanFull(),
            ])->columns(3),
            Section::make('Attribution and request context')->schema([
                Textarea::make('first_touch_context')->label('First touch (immutable)')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')->disabled()->rows(9)->columnSpanFull(),
                Textarea::make('latest_touch_context')->label('Latest touch')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')->disabled()->rows(9)->columnSpanFull(),
            ]),
            Section::make('Event timeline')->schema([
                Textarea::make('event_timeline')->formatStateUsing(function ($record): string {
                    return $record?->events()->get()->map(fn ($event) => sprintf('%s — %s%s', $event->created_at?->toDateTimeString(), $event->event, $event->details === [] ? '' : ' '.json_encode($event->details, JSON_UNESCAPED_SLASHES)))->implode("\n") ?: 'No events yet.';
                })->disabled()->rows(12)->columnSpanFull(),
            ]),
            Section::make('Analysis and delivery history')->schema([
                Textarea::make('analysis_history')->formatStateUsing(function ($record): string {
                    return $record?->analyses()->with('deliveries')->orderBy('sequence')->get()->map(fn ($analysis) => sprintf('#%s %s (%s) — deliveries: %s', $analysis->sequence, $analysis->status->value, $analysis->trigger->value, $analysis->deliveries->map(fn ($delivery) => $delivery->status->value)->implode(', ') ?: 'none'))->implode("\n") ?: 'No analyses yet.';
                })->disabled()->rows(8)->columnSpanFull(),
            ]),
        ]);
    }
}
