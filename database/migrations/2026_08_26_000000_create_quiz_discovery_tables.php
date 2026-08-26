<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('interviewing');
            $table->json('brief')->nullable();
            $table->text('system_prompt_snapshot');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('quiz_discovery_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_discovery_session_id')->constrained()->restrictOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->json('brief_snapshot')->nullable();
            $table->timestamps();
            $table->index(['quiz_discovery_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_discovery_messages');
        Schema::dropIfExists('quiz_discovery_sessions');
    }
};
