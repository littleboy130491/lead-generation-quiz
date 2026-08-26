<?php

namespace Tests\Feature;

use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_endpoint_requires_a_bearer_token(): void
    {
        config()->set('quiz_api.token', 'test-api-token');

        $this->postJson('/api/v1/quizzes/generate', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_authorized_client_can_generate_and_publish_a_quiz_draft(): void
    {
        config()->set('quiz_api.token', 'test-api-token');
        $this->app->bind(QuizDefinitionGenerator::class, fn () => new class implements QuizDefinitionGenerator
        {
            public function generate(array $brief, array $chain, string $systemPrompt): array
            {
                return [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'readiness',
                        'type' => 'question',
                        'question_type' => 'yes_no',
                        'label' => 'Are you ready?',
                        'required' => true,
                    ]],
                ];
            }
        });

        $this->withToken('test-api-token')
            ->postJson('/api/v1/quizzes/generate', $this->payload(['publish' => true]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'api-generated-quiz')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.revision.version', 1);

        $this->assertDatabaseHas('quizzes', ['slug' => 'api-generated-quiz']);
        $this->assertSame(1, Quiz::firstOrFail()->draftGenerations()->count());
    }

    public function test_authorized_client_receives_structural_scaffold_when_ai_credentials_are_missing(): void
    {
        config()->set('quiz_api.token', 'test-api-token');
        config(['ai.providers.openai.key' => null]);

        $response = $this->withToken('test-api-token')
            ->postJson('/api/v1/quizzes/generate', $this->payload([
                'slug' => 'scaffold-quiz',
                'publish' => false,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'scaffold-quiz')
            ->assertJsonPath('data.status', 'draft');

        $blocks = $response->json('data.definition.blocks');
        $this->assertIsArray($blocks);
        $this->assertNotEmpty($blocks);
        $this->assertSame(1, Quiz::query()->where('slug', 'scaffold-quiz')->count());
        $this->assertSame('completed', Quiz::query()->where('slug', 'scaffold-quiz')->firstOrFail()->draftGenerations()->firstOrFail()->status);
    }

    public function test_generation_endpoint_rejects_unsafe_or_incomplete_requests(): void
    {
        config()->set('quiz_api.token', 'test-api-token');

        $this->withToken('test-api-token')
            ->postJson('/api/v1/quizzes/generate', ['name' => 'Missing brief'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'brief.business_context']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'API Generated Quiz',
            'slug' => 'api-generated-quiz',
            'description' => 'A generated quiz.',
            'brief' => [
                'business_context' => 'A B2B consultancy that wants qualified leads.',
                'target_audience' => 'Small business owners',
                'objective' => 'Assess operational readiness',
                'desired_insight' => 'The next practical action',
                'question_count' => 5,
                'tone' => 'Clear and helpful',
            ],
            'publish' => false,
        ], $overrides);
    }
}
