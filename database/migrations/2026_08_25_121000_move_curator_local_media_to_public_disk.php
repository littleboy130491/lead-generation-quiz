<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('curator')) {
            return;
        }

        DB::table('curator')->where('disk', 'local')->orderBy('id')->each(function (object $media): void {
            if (! Storage::disk('local')->exists($media->path)) {
                return;
            }

            if (! Storage::disk('public')->exists($media->path)) {
                Storage::disk('public')->put($media->path, Storage::disk('local')->get($media->path));
            }

            DB::table('curator')->where('id', $media->id)->update(['disk' => 'public']);
        });
    }

    public function down(): void
    {
        // Existing public media remains valid; do not move files destructively.
    }
};
