<?php

namespace Tests\Unit;

use App\Ai\HeuristicQuizDefinitionGenerator;
use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use Tests\TestCase;

class HeuristicQuizDefinitionGeneratorTest extends TestCase
{
    public function test_scaffold_is_valid_v1_definition_from_brief(): void
    {
        $definition = app(HeuristicQuizDefinitionGenerator::class)->generate([
            'business_context' => 'A consultancy helps firms improve delivery. <script>alert(1)</script>',
            'target_audience' => 'Owners of service businesses',
            'objective' => 'Identify bottlenecks',
            'desired_insight' => 'The next operational action',
            'question_count' => 5,
            'tone' => 'Clear and practical',
        ]);

        app(QuizDefinitionValidator::class)->validate($definition);

        $this->assertSame(1, $definition['schema_version']);
        $this->assertSame('ai', $definition['result']['mode']);
        $this->assertStringNotContainsString('<script>', $definition['opening']['html']);
        $this->assertCount(5, array_filter($definition['blocks'], fn (array $block): bool => $block['type'] === 'question'));
    }

    public function test_hostile_brief_text_is_sanitized_into_plain_labels(): void
    {
        $definition = app(HeuristicQuizDefinitionGenerator::class)->generate([
            'business_context' => '<?php echo "x"; ?> {{ secrets }} javascript:alert(1)',
            'question_count' => 1,
        ]);

        app(QuizDefinitionValidator::class)->validate($definition);
        $this->assertStringNotContainsString('<?php', $definition['opening']['html']);
        $this->assertStringNotContainsString('javascript:', strtolower($definition['opening']['html']));
    }
}
