<?php

namespace App\Filament\Pages;

use App\Enums\AnalysisMode;
use App\Settings\ApplicationSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @property-read Schema $form
 */
class OperationalSettings extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Operational settings';

    protected static ?string $title = 'Operational settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Provider credentials remain environment-only and are never displayed or saved.';
    }

    public function mount(): void
    {
        $all = app(ApplicationSettings::class)->all();

        $this->form->fill([
            'ai' => [
                'quiz' => $all['ai.quiz'],
                'report' => $all['ai.report'],
            ],
            'prompts' => $all['prompts'],
            'spam' => $all['spam'],
            'operations' => $all['operations'],
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quiz AI provider chain')
                ->description('Ordered failover for quiz-draft generation. Use environment-configured provider names only.')
                ->schema([
                    $this->providerChainRepeater('ai.quiz'),
                ]),
            Section::make('Report AI provider chain')
                ->description('Ordered failover for report generation. Use environment-configured provider names only.')
                ->schema([
                    $this->providerChainRepeater('ai.report'),
                ]),
            Section::make('AI system prompts')
                ->description('These administrator instructions are composed into the system prompt for each request and snapshotted with generated work. Fixed application safety rules still apply and cannot be removed. Templates cannot contain PHP.')
                ->schema([
                    TextInput::make('prompts.quiz_version')->label('Quiz prompt version')->required()->maxLength(60)->regex('/^[a-z0-9._-]{1,60}$/i')
                        ->helperText('Label snapshotted with each quiz-draft generation audit record.'),
                    TextInput::make('prompts.report_version')->label('Analysis prompt version')->required()->maxLength(60)->regex('/^[a-z0-9._-]{1,60}$/i')
                        ->helperText('Label snapshotted with each analysis request.'),
                    Textarea::make('prompts.quiz_template')->label('Quiz creation system prompt')->rows(12)->maxLength(10000)->rules(['not_regex:/<\?/i'])->columnSpanFull()
                        ->helperText('Instructions used when Generate AI draft creates a quiz definition. Combined with built-in draft-only safety rules.'),
                    Textarea::make('prompts.report_template')->label('Analysis result system prompt')->rows(12)->maxLength(10000)->rules(['not_regex:/<\?/i'])->columnSpanFull()
                        ->helperText('Instructions used when AI generates analysis/report results. Combined with built-in report schema and safety rules. Optional variable: {{questions_and_answers}} (all questions/answers except those marked Exclude from AI context). Per-question {{question.ID}} / {{answer.ID}} are only available on a quiz’s own AI system prompt override.'),
                ])->columns(2),
            Section::make('Spam policy and Turnstile')
                ->schema([
                    Toggle::make('spam.turnstile_enabled')->label('Turnstile enabled')->helperText('When enabled, completed submissions must pass Turnstile before acceptance.'),
                    Select::make('spam.analysis_mode')->label('Automatic analysis mode')->required()->options([
                        AnalysisMode::Always->value => 'Always generate automatically',
                        AnalysisMode::Manual->value => 'Manual only',
                        AnalysisMode::EligibleOnly->value => 'Eligible submissions only',
                    ]),
                ])->columns(2),
            Section::make('Resume, retention, retry, and timeout policies')->schema([
                TextInput::make('operations.resume_days')->label('Resume days')->required()->numeric()->integer()->minValue(1)->maxValue(365),
                TextInput::make('operations.retention_days')->label('Retention days')->required()->numeric()->integer()->minValue(1)->maxValue(3650),
                TextInput::make('operations.retry_attempts')->label('Retry attempts')->required()->numeric()->integer()->minValue(0)->maxValue(20),
                TextInput::make('operations.timeout_seconds')->label('Timeout seconds')->required()->numeric()->integer()->minValue(5)->maxValue(3600),
            ])->columns(2),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save operational settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            DB::transaction(function () use ($data): void {
                $settings = app(ApplicationSettings::class);
                $settings->put('ai.quiz', $this->normalizedChain($data['ai']['quiz'] ?? []));
                $settings->put('ai.report', $this->normalizedChain($data['ai']['report'] ?? []));
                $settings->put('prompts', [
                    'quiz_version' => (string) ($data['prompts']['quiz_version'] ?? 'v1'),
                    'quiz_template' => (string) ($data['prompts']['quiz_template'] ?? ''),
                    'report_version' => (string) ($data['prompts']['report_version'] ?? 'v1'),
                    'report_template' => (string) ($data['prompts']['report_template'] ?? ''),
                ]);
                $settings->put('spam', [
                    'turnstile_enabled' => (bool) ($data['spam']['turnstile_enabled'] ?? false),
                    'analysis_mode' => (string) ($data['spam']['analysis_mode'] ?? AnalysisMode::Always->value),
                ]);
                $settings->put('operations', [
                    'resume_days' => (int) ($data['operations']['resume_days'] ?? 30),
                    'retention_days' => (int) ($data['operations']['retention_days'] ?? 90),
                    'retry_attempts' => (int) ($data['operations']['retry_attempts'] ?? 3),
                    'timeout_seconds' => (int) ($data['operations']['timeout_seconds'] ?? 60),
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data' => $exception->getMessage()]);
        }

        if (! app()->runningUnitTests()) {
            Notification::make()
                ->success()
                ->title('Operational settings saved.')
                ->send();
        }
    }

    private function providerChainRepeater(string $name): Repeater
    {
        return Repeater::make($name)
            ->label('Provider order')
            ->schema([
                TextInput::make('provider')->required()->maxLength(80)->regex('/^[a-z0-9._-]{1,80}$/i')->helperText('Environment provider name. Secrets are never stored here.'),
                TextInput::make('model')->required()->maxLength(120)->regex('/^[a-z0-9._:-]{1,120}$/i'),
            ])
            ->columns(2)
            ->reorderable()
            ->default([])
            ->defaultItems(0)
            ->addActionLabel('Add provider / model')
            ->columnSpanFull();
    }

    /**
     * @param  array<mixed>  $value
     * @return list<array{provider: string, model: string}>
     */
    private function normalizedChain(array $value): array
    {
        return array_values(array_map(fn (mixed $entry): array => [
            'provider' => is_array($entry) ? (string) ($entry['provider'] ?? '') : '',
            'model' => is_array($entry) ? (string) ($entry['model'] ?? '') : '',
        ], $value));
    }
}
