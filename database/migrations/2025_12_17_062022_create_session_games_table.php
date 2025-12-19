<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('session_games', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->unsignedBigInteger('total_bets')->default(0);
            $table->unsignedBigInteger('total_wins')->default(0);
            $table->unsignedBigInteger('balance')->default(0);
            $table->boolean('youtube_mode')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_games');
    }
};
