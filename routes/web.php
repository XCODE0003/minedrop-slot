<?php

use App\Http\Controllers\SessionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::post('/wallet/authenticate', [WalletController::class, 'authenticate']);
Route::post('/wallet/play', [WalletController::class, 'play']);
Route::post('/wallet/balance', [WalletController::class, 'balance']);
Route::post('/wallet/end-round', [WalletController::class, 'endRound']);
Route::get('/session/create', [SessionController::class, 'create']);
