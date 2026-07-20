<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('steam_games', 'platform')) {
            Schema::table('steam_games', function (Blueprint $table): void {
                $table->string('platform', 24)->default('steam')->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('steam_games', 'platform')) {
            Schema::table('steam_games', function (Blueprint $table): void {
                $table->dropColumn('platform');
            });
        }
    }
};
