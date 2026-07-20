<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steam_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('steam_game_id')->constrained()->cascadeOnDelete();
            $table->string('apiname');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('icongray')->nullable();
            $table->boolean('hidden')->default(false);
            $table->boolean('achieved')->default(false);
            $table->unsignedBigInteger('unlock_time')->nullable();
            $table->decimal('global_percent', 6, 3)->nullable();
            $table->timestamps();

            $table->unique(['steam_game_id', 'apiname']);
            $table->index(['achieved', 'hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steam_achievements');
    }
};
