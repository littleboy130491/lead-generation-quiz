<?php

namespace App\Ai\LaravelAi;

use App\Ai\ConfiguredAiProviders;
use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Data\ReportSchema;
use App\Ai\GenerationException;
use App\Ai\Prompt\AnalysisPromptBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Enums\Lab;

class LaravelQuizAnalysisGenerator implements QuizAnalysisGenerator
{
    public function __construct(private AnalysisPromptBuilder $prompts, private ConfiguredAiProviders $configured) {}

    public function generate(array $revision, array $answers, array $chain, string $systemPrompt): array
    {
        $attempts = [];
        $usable = $this->configured->usable($chain);
        if ($usable === []) {
            throw new GenerationException('ai_unavailable', ConfiguredAiProviders::UNAVAILABLE_MESSAGE);
        }
        foreach ($usable as $entry) {
            $provider = $entry['provider'];
            $model = $entry['model'];
            try {
                $prompt = $this->prompts->buildFromSnapshot($systemPrompt, $revision, $answers);
                $response = \Laravel\Ai\agent(instructions: $prompt->system, schema: fn (JsonSchema $schema) => $this->schema($schema))->prompt($prompt->user, provider: Lab::from($provider), model: $model, timeout: 60);
                $result = ReportSchema::validate($response->toArray());
                $attempts[] = compact('provider', 'model') + ['status' => 'completed'];

                return compact('result', 'provider', 'model', 'attempts');
            } catch (\Throwable $e) {
                $attempts[] = compact('provider', 'model') + ['status' => 'failed', 'code' => 'provider_failure', 'message' => str($e->getMessage())->limit(500)->toString()];
            }
        }
        throw new GenerationException('ai_generation_failed', 'All configured AI providers failed.', $attempts);
    }

    private function schema(JsonSchema $s): array
    {
        $item = fn () => $s->object(['title' => $s->string()->required(), 'detail' => $s->string()->required()]);

        return ['executive_summary' => $s->string()->required(), 'profile' => $s->string()->required(), 'strengths' => $s->array()->items($item())->required(), 'challenges' => $s->array()->items($item())->required(), 'recommendations' => $s->array()->items($item())->required(), 'action_plan' => $s->array()->items($item())->required(), 'disclaimer' => $s->string()->required()];
    }
}
