<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_hunt_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('steam_game_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('tags')->nullable();
            $table->string('difficulty')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('achievement_hunt_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('steam_achievement_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('none');
            $table->text('note')->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('progress_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('steam_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('achievements_total')->default(0);
            $table->unsignedInteger('achievements_unlocked')->default(0);
            $table->unsignedTinyInteger('completion_percent')->default(0);
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['steam_game_id', 'taken_at']);
        });

        Schema::create('tracker_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_settings');
        Schema::dropIfExists('progress_snapshots');
        Schema::dropIfExists('achievement_hunt_settings');
        Schema::dropIfExists('game_hunt_settings');
    }
};
