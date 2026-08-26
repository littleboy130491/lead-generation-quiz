<?php

namespace App\Filament\Resources\Quizzes\Schemas;

use App\Enums\QuizResultMode;
use App\Enums\QuizStatus;
use App\Models\Quiz;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class QuizForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Quiz editor')->persistTabInQueryString('quiz-tab')->tabs([
                Tab::make('Settings')->schema([
                    Section::make('Quiz details')->schema([
                        TextInput::make('name')->required()->maxLength(255)->live(onBlur: true),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(Quiz::class, 'slug', ignoreRecord: true)
                            ->helperText('Used in the public quiz URL. Reserved application paths are rejected.'),
                        Select::make('status')->options(QuizStatus::class)->default(QuizStatus::Draft->value)->required(),
                        Textarea::make('description')->rows(3)->maxLength(2000),
                    ])->columns(1),
                    Section::make('Access and lead settings')->schema([
                        TextInput::make('password')->password()->revealable()->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep the existing password. The value is stored only as a hash.'),
                        Checkbox::make('settings.collect_name')->label('Collect name after the questionnaire')->default(false),
                        Checkbox::make('settings.collect_company')->label('Collect company after the questionnaire')->default(false),
                        Checkbox::make('settings.collect_phone')->label('Collect phone after the questionnaire')->default(false),
                    ])->columns(1),
                    Section::make('Opening page')->description('Optional intro shown before the questionnaire. HTML is limited to a safe static subset and is never evaluated as Blade, PHP, JavaScript, or respondent data.')
                        ->schema([
                            Textarea::make('opening.html')->label('Opening HTML')->rows(10)->maxLength(40000)->live(onBlur: true)
                                ->helperText('Leave blank for no opening. Allowed: headings, paragraphs, lists, strong/emphasis, links, images, divs and spans.'),
                            TextInput::make('opening.start_button_label')->label('Start button label')->maxLength(200)->default('Start quiz')
                                ->helperText('Shown when the start button is visible. Defaults to “Start quiz”.')
                                ->visible(fn (callable $get): bool => filled($get('opening.html')) && ! $get('opening.hide_start_button')),
                            Checkbox::make('opening.hide_start_button')->label('Hide start button and show the first questions below the opening')->default(false)
                                ->helperText('When checked, opening HTML appears above page 1 with no separate start step.')
                                ->visible(fn (callable $get): bool => filled($get('opening.html')))
                                ->live(),
                        ])->columns(1),
                ]),
                Tab::make('Quiz')->schema([
                    Section::make('Quiz builder')->description('Drag blocks into their public order. IDs must remain stable after publishing. Conditions reference earlier question IDs only.')
                        ->schema([
                            Builder::make('builder_blocks')->blocks([
                                Block::make('question')->label('Question')->schema([
                                    TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Stable question ID; do not change after publishing.'),
                                    Select::make('question_type')->required()->options([
                                        'single_choice' => 'Radio (single answer)',
                                        'multiple_choice' => 'Checkbox (multiple answers)',
                                        'yes_no' => 'Yes / no',
                                        'short_text' => 'Short text',
                                        'long_text' => 'Long text',
                                    ])->live()->helperText('Checkbox questions let respondents select more than one option.'),
                                    TextInput::make('label')->required()->maxLength(500)->columnSpanFull(),
                                    Textarea::make('help')->rows(2)->maxLength(2000)->columnSpanFull(),
                                    TextInput::make('image_url')->label('Image URL')->url()->nullable()->maxLength(2048)
                                        ->helperText('Optional http(s) image shown with the question.'),
                                    TextInput::make('icon')->label('Icon')->maxLength(32)
                                        ->helperText('Optional emoji or short plain-text icon label (no HTML).'),
                                    Checkbox::make('required')->default(false),
                                    Checkbox::make('exclude_from_ai')->label('Exclude from AI context')->default(false)
                                        ->helperText('When checked, this question and its answer are omitted from analysis prompts and AI context. Default is include.'),
                                    TextInput::make('yes_score')->label('Yes score')->numeric()->integer()
                                        ->visible(fn (callable $get): bool => $get('question_type') === 'yes_no')
                                        ->helperText('Optional. Contributes to the total score when Yes is selected.'),
                                    TextInput::make('no_score')->label('No score')->numeric()->integer()
                                        ->visible(fn (callable $get): bool => $get('question_type') === 'yes_no')
                                        ->helperText('Optional. Contributes to the total score when No is selected.'),
                                    Repeater::make('options')->label('Answer options')->schema([
                                        TextInput::make('id')->required()->alphaDash()->maxLength(100),
                                        TextInput::make('value')->required()->maxLength(255),
                                        TextInput::make('label')->required()->maxLength(500),
                                        TextInput::make('score')->numeric()->integer()->helperText('Optional score for this answer.'),
                                    ])->columns(1)
                                        ->visible(fn (callable $get): bool => in_array($get('question_type'), ['single_choice', 'multiple_choice'], true))
                                        ->helperText(fn (callable $get): string => $get('question_type') === 'multiple_choice'
                                            ? 'Add each checkbox choice. Respondents may select several.'
                                            : 'Add each radio choice. Respondents select exactly one.')
                                        ->defaultItems(0)
                                        ->collapsible()
                                        ->cloneable()
                                        ->reorderable()
                                        ->columnSpanFull(),
                                    ...self::visibilityFields(),
                                ])->columns(1),
                                Block::make('content')->label('Content')->schema([
                                    TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Stable block ID.'),
                                    Textarea::make('markdown')->required()->rows(8)->maxLength(10000)->helperText('Markdown only. Executable PHP, Blade, and JavaScript are not supported.'),
                                    TextInput::make('continue_label')->maxLength(100),
                                    ...self::visibilityFields(),
                                ])->columns(1),
                                Block::make('page_break')->label('Page break')->schema([
                                    TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Separates this page from the next one.'),
                                ]),
                            ])->default([])->collapsible()->cloneable()->reorderable()->columnSpanFull(),
                        ]),
                ]),
                Tab::make('Result')->schema([
                    Section::make('Result mode')->schema([
                        Select::make('result.mode')
                            ->label('How results are produced')
                            ->options([
                                QuizResultMode::Ai->value => 'Generate with AI after contact capture',
                                QuizResultMode::Score->value => 'Predetermined results based on total score',
                            ])
                            ->default(QuizResultMode::Ai->value)
                            ->required()
                            ->live()
                            ->helperText('AI mode emails a generated report. Score mode matches answer scores to result bands below and does not queue automatic AI analysis.'),
                        Textarea::make('result.system_prompt')
                            ->label('AI system prompt')
                            ->rows(12)
                            ->maxLength(10000)
                            ->rules(['not_regex:/<\?/i'])
                            ->helperText('Optional. Overrides the global Analysis result system prompt. Variables: {{questions_and_answers}}, {{question.ID}}, {{answer.ID}} (question IDs from this quiz). Excluded questions are omitted. Leave blank to use Operational settings.')
                            ->visible(fn (callable $get): bool => $get('result.mode') === QuizResultMode::Ai->value),
                    ])->columns(1),
                    Section::make('Predetermined results')->description('Build score bands shown after the questionnaire. Ranges must not overlap. Answer option scores and yes/no scores feed the total.')
                        ->schema([
                            Builder::make('score_results')->blocks([
                                Block::make('band')->label('Score result')->schema([
                                    TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Stable result ID.'),
                                    TextInput::make('title')->required()->maxLength(500),
                                    TextInput::make('min_score')->label('Min score')->required()->numeric()->integer(),
                                    TextInput::make('max_score')->label('Max score')->required()->numeric()->integer(),
                                    Textarea::make('html')->label('Result HTML')->rows(8)->maxLength(40000)
                                        ->helperText('Static HTML for this band. Same safe subset as thank-you HTML.'),
                                ])->columns(1),
                            ])->default([])->collapsible()->cloneable()->reorderable()->columnSpanFull()
                                ->helperText('Add one band per score range. The first matching inclusive range wins.'),
                        ])->columns(1)
                        ->visible(fn (callable $get): bool => $get('result.mode') === QuizResultMode::Score->value),
                ]),
                Tab::make('Thank you')->schema([
                    Section::make('Thank-you page')->description('The global default is set under Branding & design. AI-result quizzes always show a thank-you page after contact capture.')
                        ->schema([
                            Checkbox::make('thank_you.enabled')
                                ->label('Show thank-you page after contact capture')
                                ->default(true)
                                ->live()
                                ->disabled(fn (callable $get): bool => $get('result.mode') !== QuizResultMode::Score->value)
                                ->dehydrated()
                                ->helperText(fn (callable $get): string => $get('result.mode') === QuizResultMode::Score->value
                                    ? 'When disabled, the completion page shows the matched predetermined result instead.'
                                    : 'Required for AI results. Disable is available only for predetermined score results.'),
                            Textarea::make('thank_you.html')
                                ->label('Override thank-you HTML')
                                ->rows(12)
                                ->maxLength(40000)
                                ->helperText('Leave blank to use the global Branding & design thank-you HTML. Allowed: headings, paragraphs, lists, strong/emphasis, links, images, divs and spans.')
                                ->visible(fn (callable $get): bool => (bool) $get('thank_you.enabled') || $get('result.mode') !== QuizResultMode::Score->value),
                        ])->columns(1),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    /** @return array<Component> */
    private static function visibilityFields(): array
    {
        return [
            Builder::make('visibility')->label('Show this block when')->blocks(self::conditionBlocks(3))
                ->helperText('Build all/any groups from leaf rules. Publish validation permits references to earlier questions only.')
                ->collapsible()->cloneable(),
        ];
    }

    /** @return array<Block> */
    private static function conditionBlocks(int $depth): array
    {
        $leaf = Block::make('rule')->label('Rule')->schema([
            TextInput::make('question_id')->label('Earlier question ID')->required()->maxLength(100),
            Select::make('operator')->required()->options([
                'equals' => 'Equals', 'not_equals' => 'Does not equal', 'in' => 'Is one of', 'not_in' => 'Is not one of',
                'contains' => 'Contains', 'empty' => 'Is empty', 'not_empty' => 'Is not empty', 'greater_than' => 'Greater than', 'less_than' => 'Less than',
            ]),
            TextInput::make('value')->label('Comparison value (comma-separated for in/not in)')->maxLength(1000),
        ])->columns(1);
        if ($depth === 0) {
            return [$leaf];
        }

        return [
            $leaf,
            Block::make('all')->label('All rules must match')->schema([Builder::make('children')->label('All')->blocks(self::conditionBlocks($depth - 1))->minItems(1)]),
            Block::make('any')->label('Any rule may match')->schema([Builder::make('children')->label('Any')->blocks(self::conditionBlocks($depth - 1))->minItems(1)]),
        ];
    }

    public static function passwordForStorage(?string $password): ?string
    {
        return filled($password) ? Hash::make($password) : null;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function toDefinition(array $state): array
    {
        $blocks = $state['blocks'] ?? $state['builder_blocks'] ?? [];

        $mode = (string) data_get($state, 'result.mode', QuizResultMode::Ai->value);
        if (! in_array($mode, [QuizResultMode::Ai->value, QuizResultMode::Score->value], true)) {
            $mode = QuizResultMode::Ai->value;
        }

        $definition = [
            'schema_version' => 1,
            'result' => ['mode' => $mode],
            'blocks' => array_values(array_map(static function (array $block): array {
                $data = $block['data'] ?? $block;
                $type = $block['type'] ?? $data['type'] ?? null;
                $data['type'] = $type;
                if (array_key_exists('visibility', $data)) {
                    $data['visibility'] = self::conditionForStorage($data['visibility']);
                }
                if (($type === 'question') && is_array($data['options'] ?? null)) {
                    $data['options'] = array_values(array_map(static fn (array $option): array => self::optionForStorage($option), $data['options']));
                }
                foreach (['yes_score', 'no_score'] as $scoreKey) {
                    if (array_key_exists($scoreKey, $data)) {
                        if ($data[$scoreKey] === null || $data[$scoreKey] === '') {
                            unset($data[$scoreKey]);
                        } else {
                            $data[$scoreKey] = (int) $data[$scoreKey];
                        }
                    }
                }
                if (($type === 'question') && array_key_exists('exclude_from_ai', $data)) {
                    $data['exclude_from_ai'] = (bool) $data['exclude_from_ai'];
                    if ($data['exclude_from_ai'] !== true) {
                        unset($data['exclude_from_ai']);
                    }
                }

                return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
            }, $blocks)),
        ];

        if ($mode === QuizResultMode::Ai->value) {
            $systemPrompt = trim((string) data_get($state, 'result.system_prompt', ''));
            if ($systemPrompt !== '') {
                $definition['result']['system_prompt'] = $systemPrompt;
            }
        }

        $opening = self::openingForStorage($state['opening'] ?? null);
        if ($opening !== null) {
            $definition['opening'] = $opening;
        }

        if ($mode === QuizResultMode::Score->value) {
            $scoreResults = self::scoreResultsForStorage($state['score_results'] ?? null);
            if ($scoreResults !== null) {
                $definition['score_results'] = $scoreResults;
            }
        }

        $thankYou = self::thankYouForStorage($state['thank_you'] ?? null, $mode);
        if ($thankYou !== null) {
            $definition['thank_you'] = $thankYou;
        }

        return $definition;
    }

    /** @param array<string, mixed> $option @return array<string, mixed> */
    private static function optionForStorage(array $option): array
    {
        if (array_key_exists('score', $option)) {
            if ($option['score'] === null || $option['score'] === '') {
                unset($option['score']);
            } else {
                $option['score'] = (int) $option['score'];
            }
        }

        return array_filter($option, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<int|string, mixed>|null $results @return list<array<string, mixed>>|null */
    private static function scoreResultsForStorage(?array $results): ?array
    {
        if (! is_array($results) || $results === []) {
            return null;
        }

        $bands = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $data = $result['data'] ?? $result;
            $id = trim((string) ($data['id'] ?? ''));
            $title = trim((string) ($data['title'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $band = [
                'id' => $id,
                'title' => $title,
                'min_score' => (int) ($data['min_score'] ?? 0),
                'max_score' => (int) ($data['max_score'] ?? 0),
            ];
            $html = trim((string) ($data['html'] ?? ''));
            if ($html !== '') {
                $band['html'] = $html;
            }
            $bands[] = $band;
        }

        return $bands === [] ? null : array_values($bands);
    }

    /** @param array<string, mixed>|null $thankYou @return array{enabled: bool, html?: string}|null */
    private static function thankYouForStorage(?array $thankYou, string $mode): ?array
    {
        $enabled = $mode === QuizResultMode::Ai->value
            ? true
            : (bool) ($thankYou['enabled'] ?? true);
        $payload = ['enabled' => $enabled];
        $html = trim((string) ($thankYou['html'] ?? ''));
        if ($enabled && $html !== '') {
            $payload['html'] = $html;
        }

        if ($mode === QuizResultMode::Ai->value && ! isset($payload['html'])) {
            return null;
        }

        return $payload;
    }

    /** @param array<string, mixed>|null $opening @return array{html: string, start_button_label: string, hide_start_button: bool}|null */
    private static function openingForStorage(?array $opening): ?array
    {
        if (! is_array($opening)) {
            return null;
        }

        $html = trim((string) ($opening['html'] ?? ''));
        if ($html === '') {
            return null;
        }

        $label = trim((string) ($opening['start_button_label'] ?? ''));

        return [
            'html' => $html,
            'start_button_label' => $label !== '' ? $label : 'Start quiz',
            'hide_start_button' => (bool) ($opening['hide_start_button'] ?? false),
        ];
    }

    /** @param array<int|string, mixed>|null $state */
    private static function conditionForStorage(?array $state): ?array
    {
        if (! $state) {
            return null;
        }
        if (isset($state['question_id'])) {
            if (in_array($state['operator'] ?? null, ['in', 'not_in'], true) && is_string($state['value'] ?? null)) {
                $state['value'] = array_values(array_filter(array_map('trim', explode(',', $state['value']))));
            }

            return array_filter($state, static fn (mixed $value): bool => $value !== null && $value !== '');
        }
        $item = reset($state);
        if (! is_array($item)) {
            return null;
        }
        $type = $item['type'] ?? null;
        $data = $item['data'] ?? $item;
        if ($type === 'rule') {
            return self::conditionForStorage($data);
        }
        if (in_array($type, ['all', 'any'], true)) {
            $children = array_values(array_filter(array_map(static fn (mixed $child): ?array => is_array($child) ? self::conditionForStorage([$child]) : null, $data['children'] ?? [])));

            return $children === [] ? null : [$type => $children];
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function toFormState(Quiz $quiz): array
    {
        $state = $quiz->attributesToArray();
        unset($state['password_hash']);
        $state['password'] = null;
        $definition = $quiz->draft_definition ?? [];
        $state['builder_blocks'] = array_map(static fn (array $block): array => [
            'type' => $block['type'],
            'data' => collect($block)->except('type')->all(),
        ], $definition['blocks'] ?? []);
        $state['opening'] = [
            'html' => data_get($definition, 'opening.html'),
            'start_button_label' => data_get($definition, 'opening.start_button_label', 'Start quiz'),
            'hide_start_button' => (bool) data_get($definition, 'opening.hide_start_button', false),
        ];
        $state['result'] = [
            'mode' => data_get($definition, 'result.mode', QuizResultMode::Ai->value),
            'system_prompt' => data_get($definition, 'result.system_prompt'),
        ];
        $state['score_results'] = array_map(static fn (array $band): array => [
            'type' => 'band',
            'data' => $band,
        ], $definition['score_results'] ?? []);
        $state['thank_you'] = [
            'enabled' => (bool) data_get($definition, 'thank_you.enabled', true),
            'html' => data_get($definition, 'thank_you.html'),
        ];

        return $state;
    }
}
