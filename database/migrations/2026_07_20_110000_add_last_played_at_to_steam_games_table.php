<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('steam_games', 'last_played_at')) {
            return;
        }

        Schema::table('steam_games', function (Blueprint $table): void {
            $table->timestamp('last_played_at')->nullable()->after('is_current');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('steam_games', 'last_played_at')) {
            return;
        }

        Schema::table('steam_games', function (Blueprint $table): void {
            $table->dropColumn('last_played_at');
        });
    }
};
