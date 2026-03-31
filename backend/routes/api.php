<?php

use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::put('/user', [ProfileController::class, 'update']);
    Route::get('/cards/search', [CardController::class, 'search']);
    Route::post('/collection/scan', [CollectionController::class, 'scan']);
    Route::get('/collection', [CollectionController::class, 'index']);
    Route::get('/collection/search', [CollectionController::class, 'search']);
    Route::post('/collection', [CollectionController::class, 'store']);
    Route::patch('/collection/{card}', [CollectionController::class, 'update']);

    Route::get('/chat/conversations', [ChatController::class, 'indexConversations']);
    Route::post('/chat/conversations', [ChatController::class, 'storeConversation']);
    Route::get('/chat/conversations/{conversation}', [ChatController::class, 'showConversation']);
    Route::post('/chat/conversations/{conversation}/messages', [ChatController::class, 'sendMessage']);
    Route::post('/chat/conversations/{conversation}/create-deck', [ChatController::class, 'createDeck']);

    Route::apiResource('decks', DeckController::class);
    Route::post('/decks/{deck}/cards', [DeckController::class, 'addCard']);
    Route::delete('/decks/{deck}/cards/{cardId}', [DeckController::class, 'removeCard']);
});
