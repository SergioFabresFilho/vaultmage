<?php

use App\Http\Controllers\Api\CollectionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/collection/scan', [CollectionController::class, 'scan']);
    Route::get('/collection', [CollectionController::class, 'index']);
    Route::post('/collection', [CollectionController::class, 'store']);
});
