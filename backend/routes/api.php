<?php

use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CollectionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/cards/search', [CardController::class, 'search']);
    Route::post('/collection/scan', [CollectionController::class, 'scan']);
    Route::get('/collection', [CollectionController::class, 'index']);
    Route::get('/collection/search', [CollectionController::class, 'search']);
    Route::post('/collection', [CollectionController::class, 'store']);
});
