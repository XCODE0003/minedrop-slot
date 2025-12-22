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
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bet_id')->unique();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('payout')->default(0);
            $table->decimal('payout_multiplier', 8, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('mode')->default('BASE');
            $table->boolean('is_win')->default(false);
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};







