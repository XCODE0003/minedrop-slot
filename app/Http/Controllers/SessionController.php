<?php

namespace App\Http\Controllers;

use App\Models\SessionGame;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class SessionController extends Controller
{
    public function create(Request $request)
    {
        $sessionGame = SessionGame::create([
            'session_uuid' => Str::uuid(),
            'total_bets' => 0,
            'total_wins' => 0,
            'balance' => $request->balance,
            'youtube_mode' => $request->youtube_mode,
            'is_admin' => false,
            'is_active' => true,
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Session created successfully',
            'data' => $sessionGame,
        ]);
    }
}