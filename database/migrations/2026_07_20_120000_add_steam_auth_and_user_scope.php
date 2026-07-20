<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'steam_id')) {
                $table->string('steam_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'profile_url')) {
                $table->string('profile_url')->nullable()->after('avatar');
            }
        });

        Schema::table('steam_games', function (Blueprint $table): void {
            if (! Schema::hasColumn('steam_games', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
        });

        try {
            Schema::table('steam_games', function (Blueprint $table): void {
                $table->dropUnique(['appid']);
            });
        } catch (Throwable) {
            //
        }

        try {
            Schema::table('steam_games', function (Blueprint $table): void {
                $table->unique(['user_id', 'appid']);
            });
        } catch (Throwable) {
            //
        }
    }

    public function down(): void
    {
        Schema::table('steam_games', function (Blueprint $table): void {
            try {
                $table->dropUnique(['user_id', 'appid']);
            } catch (Throwable) {
                //
            }
        });

        Schema::table('steam_games', function (Blueprint $table): void {
            if (Schema::hasColumn('steam_games', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            foreach (['profile_url', 'avatar', 'steam_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
