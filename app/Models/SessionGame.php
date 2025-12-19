<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionGame extends Model
{
    protected $fillable = [
        'session_uuid',
        'total_bets',
        'total_wins',
        'youtube_mode',
        'is_admin',
        'balance',
        'is_active',
    ];

    protected $casts = [
        'youtube_mode' => 'boolean',
        'is_admin' => 'boolean',
        'is_active' => 'boolean',
        'balance' => 'integer',
        'total_bets' => 'integer',
        'total_wins' => 'integer',
    ];

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }
}
