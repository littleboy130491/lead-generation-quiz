<?php

namespace Tests\Feature;

use App\Enums\QuizResultMode;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\LeadGenerationQuizSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadGenerationQuizSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_published_demo_quiz_with_an_immutable_active_revision(): void
    {
        $this->seed(AdminRoleSeeder::class);
        $publisher = User::factory()->create();
        $publisher->assignRole('admin');

        app(LeadGenerationQuizSeeder::class)->run();
        app(LeadGenerationQuizSeeder::class)->run();

        $quiz = Quiz::query()->where('slug', 'business-readiness-check')->firstOrFail();
        $definition = $quiz->activeRevision->definition;

        $this->assertSame('Business Readiness Check', $quiz->name);
        $this->assertSame('published', $quiz->status->value);
        $this->assertNotNull($quiz->activeRevision);
        $this->assertSame(1, $quiz->activeRevision->version);
        $this->assertSame(1, $definition['schema_version']);
        $this->assertSame(QuizResultMode::Ai->value, $definition['result']['mode']);
        $this->assertSame('Start quiz', $definition['opening']['start_button_label']);
        $this->assertNotEmpty($definition['blocks']);
        $this->assertTrue(collect($definition['blocks'])->contains(
            fn (array $block): bool => ($block['id'] ?? null) === 'internal-note' && ($block['exclude_from_ai'] ?? false) === true
        ));
        $this->assertSame(1, $quiz->revisions()->count());
        $this->assertTrue((bool) $quiz->settings['collect_name']);
    }
}
