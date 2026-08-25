<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_deliveries', function (Blueprint $table) {
            $table->string('automatic_key')->nullable()->after('trigger');
            $table->unique(['analysis_id', 'automatic_key']);
        });
    }

    public function down(): void
    {
        Schema::table('report_deliveries', fn (Blueprint $table) => $table->dropUnique(['analysis_id', 'automatic_key'])->dropColumn('automatic_key'));
    }
};
