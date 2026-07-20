<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steam_games', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('appid')->unique();
            $table->string('name');
            $table->string('img_icon_url')->nullable();
            $table->unsignedInteger('playtime_forever')->default(0);
            $table->unsignedInteger('playtime_2weeks')->default(0);
            $table->unsignedInteger('achievements_total')->default(0);
            $table->unsignedInteger('achievements_unlocked')->default(0);
            $table->boolean('is_current')->default(false);
            $table->timestamp('achievements_synced_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steam_games');
    }
};
