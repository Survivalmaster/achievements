<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steam_games', function (Blueprint $table): void {
            if (! Schema::hasColumn('steam_games', 'external_id')) {
                $table->string('external_id')->nullable()->after('platform');
            }

            if (! Schema::hasColumn('steam_games', 'platform_meta')) {
                $table->json('platform_meta')->nullable()->after('external_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('steam_games', function (Blueprint $table): void {
            if (Schema::hasColumn('steam_games', 'platform_meta')) {
                $table->dropColumn('platform_meta');
            }

            if (Schema::hasColumn('steam_games', 'external_id')) {
                $table->dropColumn('external_id');
            }
        });
    }
};
