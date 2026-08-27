<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->foreignId('quiz_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->uuid('generation_token')->nullable()->after('system_prompt_snapshot');
            $table->timestamp('generation_started_at')->nullable()->after('generation_token');
            $table->timestamp('generation_finished_at')->nullable()->after('generation_started_at');
            $table->string('generation_error_code', 64)->nullable()->after('generation_finished_at');
            $table->text('generation_error_message')->nullable()->after('generation_error_code');
            $table->index(['status', 'generation_started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'generation_started_at']);
            $table->dropConstrainedForeignId('quiz_id');
            $table->dropColumn([
                'generation_token',
                'generation_started_at',
                'generation_finished_at',
                'generation_error_code',
                'generation_error_message',
            ]);
        });
    }
};
