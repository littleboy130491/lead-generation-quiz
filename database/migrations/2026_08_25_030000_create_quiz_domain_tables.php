<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('status')->default('draft');
            $t->json('draft_definition')->nullable();
            $t->foreignId('active_revision_id')->nullable();
            $t->string('password_hash')->nullable();
            $t->json('settings')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('quiz_draft_generations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $t->string('brief_hash', 64);
            $t->json('requested_provider_chain');
            $t->string('prompt_version', 60);
            $t->text('system_prompt_snapshot');
            $t->string('status');
            $t->string('result_hash', 64)->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('requested_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();
            $t->index(['quiz_id', 'requested_at']);
        });
        Schema::create('quiz_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('definition');
            $t->text('report_prompt_snapshot')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at');
            $t->timestamps();
            $t->unique(['quiz_id', 'version']);
        });
        Schema::table('quizzes', function (Blueprint $t) {
            $t->foreign('active_revision_id')->references('id')->on('quiz_revisions')->nullOnDelete();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $t->foreignId('quiz_revision_id')->constrained()->restrictOnDelete();
            $t->string('resume_token_hash')->nullable()->unique();
            $t->string('status')->default('in_progress');
            $t->string('email')->nullable();
            $t->string('name')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->json('answers_snapshot')->nullable();
            $t->json('quiz_snapshot')->nullable();
            $t->unsignedInteger('current_page')->default(0);
            $t->json('metadata')->nullable();
            $t->json('first_touch_context')->nullable();
            $t->json('latest_touch_context')->nullable();
            $t->unsignedBigInteger('preferred_analysis_id')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('last_activity_at');
            $t->timestamp('questionnaire_completed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('submission_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->string('event');
            $t->json('context_snapshot')->nullable();
            $t->json('details')->nullable();
            $t->timestamps();
            $t->index(['submission_id', 'created_at']);
        });
        Schema::create('analyses', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('status')->default('queued');
            $t->string('trigger');
            $t->string('automatic_key')->nullable();
            $t->boolean('created_manually')->default(false);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('requested_provider_chain')->nullable();
            $t->string('actual_provider')->nullable();
            $t->string('actual_model')->nullable();
            $t->boolean('failover_occurred')->default(false);
            $t->json('provider_attempts')->nullable();
            $t->string('prompt_version')->nullable();
            $t->text('system_prompt_snapshot')->nullable();
            $t->json('input_snapshot')->nullable();
            $t->json('structured_result')->nullable();
            $t->longText('rendered_report')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->unsignedInteger('recovery_count')->default(0);
            $t->unsignedBigInteger('execution_generation')->default(0);
            $t->string('execution_lease')->nullable()->index();
            $t->timestamp('lease_expires_at')->nullable()->index();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['submission_id', 'sequence']);
            $t->unique(['submission_id', 'automatic_key']);
        });
        Schema::create('report_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('analysis_id')->constrained()->restrictOnDelete();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->string('recipient_email');
            $t->string('status')->default('queued');
            $t->string('trigger');
            $t->boolean('sent_manually')->default(false);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('provider')->nullable();
            $t->string('provider_message_id')->nullable()->index();
            $t->string('template_identifier')->nullable();
            $t->string('template_version')->nullable();
            $t->string('subject_snapshot')->nullable();
            $t->longText('html_snapshot')->nullable();
            $t->longText('text_snapshot')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->unsignedInteger('recovery_count')->default(0);
            $t->unsignedBigInteger('execution_generation')->default(0);
            $t->string('execution_lease')->nullable()->index();
            $t->timestamp('lease_expires_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('analyses');
        Schema::dropIfExists('submission_events');
        Schema::dropIfExists('submissions');
        Schema::table('quizzes', fn (Blueprint $t) => $t->dropForeign(['active_revision_id']));
        Schema::dropIfExists('quiz_revisions');
        Schema::dropIfExists('quiz_draft_generations');
        Schema::dropIfExists('quizzes');
    }
};
