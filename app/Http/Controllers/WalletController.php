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
        $mode = $request->mode ?? 'BASE';

        $playService = new PlayService($sessionGame, $amount);
        $multiplier = $request->multiplier;
        $playService->setMultiplierSeed(10);
        // Если режим BONUS, запускаем бонусную игр
        if ($mode === 'BONUS') {
            $sessionGame->balance -= $amount * 100;
            $sessionGame->save();
            return $playService->playBonus($multiplier);
        }


        $win = $multiplier * $amount;
        $sessionGame->balance -= $amount;
        $sessionGame->balance += $win;
        $sessionGame->save();

        return $playService->play();
    }


    public function authenticate(Request $request)
    {
        $sessionGame = SessionGame::where('session_uuid', $request->sessionID)->first();
        $data = [
            'balance' => [
                'amount' => $sessionGame->balance,
                'currency' => 'RUB'
            ],
            'round' => null,
            'config' => [
                'gameID' => '',
                'minBet' => 10000000,
                'maxBet' => 100000000000,
                'stepBet' => 1000000,
                'defaultBetLevel' => 100000000,
                'betLevels' => [
                    10000000,
                    20000000,
                    30000000,
                    40000000,
                    50000000,
                    60000000,
                    70000000,
                    80000000,
                    90000000,
                    100000000,
                    200000000,
                    300000000,
                    400000000,
                    500000000,
                    1000000000,
                    2000000000,
                    3000000000,
                    4000000000,
                    5000000000,
                    6000000000,
                    7000000000,
                    8000000000,
                    9000000000,
                    10000000000
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
                    'disabledBuyFeature' => true,
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
                'amount' => $sessionGame->balance,
                'currency' => 'RUB'
            ]
        ]);
    }
    public function balance(Request $request)
    {
        $sessionGame = SessionGame::where('session_uuid', $request->sessionID)->first();
        if (!$sessionGame) {
            return response()->json([
                'error' => 'Session game not found'
            ], 404);
        }

        return response()->json([
            'balance' => [
                'amount' => $sessionGame->balance,
                'currency' => 'RUB'
            ]
        ]);
    }
}
