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
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 24)->default('steam');
            $table->string('external_id')->nullable();
            $table->json('platform_meta')->nullable();
            $table->unsignedBigInteger('appid');
            $table->string('name');
            $table->string('img_icon_url')->nullable();
            $table->unsignedInteger('playtime_forever')->default(0);
            $table->unsignedInteger('playtime_2weeks')->default(0);
            $table->unsignedInteger('achievements_total')->default(0);
            $table->unsignedInteger('achievements_unlocked')->default(0);
            $table->boolean('is_current')->default(false);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamp('achievements_synced_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'appid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steam_games');
    }
};
