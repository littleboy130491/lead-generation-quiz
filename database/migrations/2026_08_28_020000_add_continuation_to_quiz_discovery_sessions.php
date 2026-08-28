<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->foreignId('continued_from_session_id')
                ->nullable()
                ->after('id')
                ->constrained('quiz_discovery_sessions')
                ->restrictOnDelete();
            $table->unique('continued_from_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_discovery_sessions', function (Blueprint $table): void {
            $table->dropUnique(['continued_from_session_id']);
            $table->dropConstrainedForeignId('continued_from_session_id');
        });
    }
};
