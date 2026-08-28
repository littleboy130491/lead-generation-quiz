<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->string('mode', 16)->default('create')->after('quiz_id');
            $table->json('source_quiz_snapshot')->nullable()->after('brief');
            $table->index(['user_id', 'mode', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'mode', 'updated_at']);
            $table->dropColumn(['mode', 'source_quiz_snapshot']);
        });
    }
};
