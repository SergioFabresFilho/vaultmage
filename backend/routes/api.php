<?php

use App\Http\Controllers\Api\CollectionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/collection/scan', [CollectionController::class, 'scan']);
    Route::get('/collection', [CollectionController::class, 'index']);
    Route::post('/collection', [CollectionController::class, 'store']);
});
