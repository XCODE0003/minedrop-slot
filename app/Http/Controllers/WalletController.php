<?php

namespace App\Http\Controllers;

use App\Models\SessionGame;
use App\Service\Game\MineDropService;
use App\Service\Game\PlayService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function play(Request $request)
    {
        $sessionGame = SessionGame::where('session_uuid', $request->sessionID)->first();

        if (!$sessionGame) {
            return response()->json([
                'error' => 'Session game not found'
            ], 404);
        }

        $amount = $request->amount;
        $currency = $request->currency;

        $playService = new PlayService($sessionGame, $amount);
        return $playService->play();
    }
    public function authenticate(){
        $data = [
            'balance' => [
                'amount' => 1000000000,
                'currency' => 'USD'
            ],
            'round' => null,
            'config' => [
                'gameID' => '',
                'minBet' => 100000,
                'maxBet' => 1000000000,
                'stepBet' => 10000,
                'defaultBetLevel' => 1000000,
                'betLevels' => [
                    100000,
                    200000,
                    400000,
                    600000,
                    800000,
                    1000000,
                    1200000,
                    1400000,
                    1600000,
                    1800000,
                    2000000,
                    3000000,
                    4000000,
                    5000000,
                    6000000,
                    7000000,
                    8000000,
                    9000000,
                    10000000,
                    12000000,
                    14000000,
                    16000000,
                    18000000,
                    20000000,
                    30000000,
                    40000000,
                    50000000,
                    75000000,
                    100000000,
                    150000000,
                    200000000,
                    250000000,
                    300000000,
                    350000000,
                    400000000,
                    450000000,
                    500000000,
                    750000000,
                    1000000000
                ],
                'betModes' => [],
                'jurisdiction' => [
                    'socialCasino' => false,
                    'disabledFullscreen' => false,
                    'disabledTurbo' => false,
                    'disabledSuperTurbo' => false,
                    'disabledAutoplay' => false,
                    'disabledSlamstop' => false,
                    'disabledSpacebar' => false,
                    'disabledBuyFeature' => false,
                    'displayNetPosition' => false,
                    'displayRTP' => false,
                    'displaySessionTimer' => false,
                    'minimumRoundDuration' => 0
                ]
            ],
            'meta' => null
        ];
        return response()->json($data);
    }

    public function endRound(Request $request)
    {
        $sessionGame = SessionGame::where('session_uuid', $request->sessionID)->first();
        if (!$sessionGame) {
            return response()->json([
                'error' => 'Session game not found'
            ], 404);
        }
        return response()->json([
            'balance' => [
                'amount' => $sessionGame->balance * 100,
                'currency' => 'USD'
            ]
        ]);
    }
}
