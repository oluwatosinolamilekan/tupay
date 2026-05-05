<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'throttle:auth'])->group(function (): void {
    Route::post('/2fa/verify', [AuthController::class, 'verify']);
});

Route::middleware(['auth:sanctum', 'throttle:finance'])->group(function (): void {
    Route::get('/accounts/{account}', [AccountController::class, 'show']);
    Route::get('/ledger/{wallet}', [LedgerController::class, 'index']);
});

Route::middleware(['auth:sanctum', '2fa', 'throttle:finance'])->group(function (): void {
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::post('/accounts/{account}/deposits', [DepositController::class, 'store']);
    Route::post('/swap', [SwapController::class, 'store']);
    Route::post('/transfer', [TransactionController::class, 'store']);
});

Route::post('/webhooks/settlement', [SettlementWebhookController::class, 'store'])
    ->middleware(['webhook.signature', 'throttle:webhooks']);
