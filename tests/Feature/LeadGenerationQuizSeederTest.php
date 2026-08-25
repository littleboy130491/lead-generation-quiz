<?php

namespace Tests\Feature;

use App\Models\Quiz;
use Database\Seeders\LeadGenerationQuizSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadGenerationQuizSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_published_demo_quiz_with_an_immutable_active_revision(): void
    {
        app(LeadGenerationQuizSeeder::class)->run();

        $quiz = Quiz::query()->where('slug', 'business-readiness-check')->firstOrFail();

        $this->assertSame('Business Readiness Check', $quiz->name);
        $this->assertSame('published', $quiz->status->value);
        $this->assertNotNull($quiz->activeRevision);
        $this->assertSame(1, $quiz->activeRevision->version);
        $this->assertSame(1, $quiz->activeRevision->definition['schema_version']);
        $this->assertNotEmpty($quiz->activeRevision->definition['blocks']);
        $this->assertStringContainsString("\n", $quiz->activeRevision->definition['blocks'][0]['markdown']);
        $this->assertStringNotContainsString('\\n', $quiz->activeRevision->definition['blocks'][0]['markdown']);
    }
}
