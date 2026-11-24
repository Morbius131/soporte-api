<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('tickets', TicketController::class);

    Route::get('tickets/{ticket}/comments', [CommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [CommentController::class, 'store']);

    Route::get('stats', StatsController::class);
    Route::get('agents', AgentController::class);
});
