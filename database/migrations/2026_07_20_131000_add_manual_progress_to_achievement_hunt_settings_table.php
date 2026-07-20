<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievement_hunt_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('achievement_hunt_settings', 'manual_progress_current')) {
                $table->unsignedBigInteger('manual_progress_current')->nullable()->after('tags');
            }

            if (! Schema::hasColumn('achievement_hunt_settings', 'manual_progress_target')) {
                $table->unsignedBigInteger('manual_progress_target')->nullable()->after('manual_progress_current');
            }
        });
    }

    public function down(): void
    {
        Schema::table('achievement_hunt_settings', function (Blueprint $table): void {
            foreach (['manual_progress_target', 'manual_progress_current'] as $column) {
                if (Schema::hasColumn('achievement_hunt_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
