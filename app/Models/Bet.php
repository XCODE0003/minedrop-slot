<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    protected $fillable = [
        'session_game_id',
        'bet_id',
        'amount',
        'payout',
        'multiplier',
        'payout_multiplier', // для обратной совместимости
        'currency',
        'mode',
        'is_win',
        'state',
    ];

    protected $casts = [
        'state' => 'array',
        'is_win' => 'boolean',
        'payout_multiplier' => 'float',
    ];

    public function sessionGame(): BelongsTo
    {
        return $this->belongsTo(SessionGame::class);
    }
}

