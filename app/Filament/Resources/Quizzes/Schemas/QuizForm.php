<?php

namespace App\Filament\Resources\Quizzes\Schemas;

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
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class QuizForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quiz details')->schema([
                TextInput::make('name')->required()->maxLength(255)->live(onBlur: true),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(Quiz::class, 'slug', ignoreRecord: true)
                    ->helperText('Used in the public quiz URL. Reserved application paths are rejected.'),
                Select::make('status')->options(QuizStatus::class)->default(QuizStatus::Draft->value)->required(),
                Textarea::make('description')->rows(3)->maxLength(2000)->columnSpanFull(),
            ])->columns(2),
            Section::make('Access and lead settings')->schema([
                TextInput::make('password')->password()->revealable()->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave blank to keep the existing password. The value is stored only as a hash.'),
                Checkbox::make('settings.collect_name')->label('Collect name after the questionnaire')->default(false),
                Checkbox::make('settings.collect_company')->label('Collect company after the questionnaire')->default(false),
                Checkbox::make('settings.collect_phone')->label('Collect phone after the questionnaire')->default(false),
            ])->columns(2),
            Section::make('Quiz builder')->description('Drag blocks into their public order. IDs must remain stable after publishing. Conditions reference earlier question IDs only.')
                ->schema([
                    Builder::make('builder_blocks')->blocks([
                        Block::make('question')->label('Question')->schema([
                            TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Stable question ID; do not change after publishing.'),
                            Select::make('question_type')->required()->options([
                                'single_choice' => 'Single choice', 'multiple_choice' => 'Multiple choice', 'yes_no' => 'Yes / no',
                                'short_text' => 'Short text', 'long_text' => 'Long text',
                            ])->live(),
                            TextInput::make('label')->required()->maxLength(500)->columnSpanFull(),
                            Textarea::make('help')->rows(2)->maxLength(2000)->columnSpanFull(),
                            Checkbox::make('required')->default(false),
                            Repeater::make('options')->schema([
                                TextInput::make('id')->required()->alphaDash()->maxLength(100),
                                TextInput::make('value')->required()->maxLength(255),
                                TextInput::make('label')->required()->maxLength(500),
                            ])->columns(3)->visible(fn (callable $get): bool => in_array($get('question_type'), ['single_choice', 'multiple_choice'], true))->defaultItems(0)->columnSpanFull(),
                            ...self::visibilityFields(),
                        ])->columns(2),
                        Block::make('content')->label('Content')->schema([
                            TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Stable block ID.'),
                            Textarea::make('markdown')->required()->rows(8)->maxLength(10000)->columnSpanFull()->helperText('Markdown only. Executable PHP, Blade, and JavaScript are not supported.'),
                            TextInput::make('continue_label')->maxLength(100),
                            ...self::visibilityFields(),
                        ])->columns(2),
                        Block::make('page_break')->label('Page break')->schema([
                            TextInput::make('id')->required()->alphaDash()->maxLength(100)->helperText('Separates this page from the next one.'),
                        ]),
                    ])->default([])->columnSpanFull(),
                ]),
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
        ])->columns(3);
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

        return [
            'schema_version' => 1,
            'blocks' => array_values(array_map(static function (array $block): array {
                $data = $block['data'] ?? $block;
                $type = $block['type'] ?? $data['type'] ?? null;
                $data['type'] = $type;
                if (array_key_exists('visibility', $data)) {
                    $data['visibility'] = self::conditionForStorage($data['visibility']);
                }

                return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
            }, $blocks)),
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
        $state['builder_blocks'] = array_map(static fn (array $block): array => [
            'type' => $block['type'],
            'data' => collect($block)->except('type')->all(),
        ], $quiz->draft_definition['blocks'] ?? []);

        return $state;
    }
}
