<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steam_achievements', function (Blueprint $table): void {
            if (! Schema::hasColumn('steam_achievements', 'progress_current')) {
                $table->unsignedBigInteger('progress_current')->nullable()->after('global_percent');
            }

            if (! Schema::hasColumn('steam_achievements', 'progress_target')) {
                $table->unsignedBigInteger('progress_target')->nullable()->after('progress_current');
            }
        });
    }

    public function down(): void
    {
        Schema::table('steam_achievements', function (Blueprint $table): void {
            foreach (['progress_target', 'progress_current'] as $column) {
                if (Schema::hasColumn('steam_achievements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
