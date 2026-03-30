<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeckController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Auth::user()->decks()->withCount('cards')->latest()->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'nullable|string|in:standard,modern,pioneer,legacy,vintage,commander,brawl,pauper,casual',
            'description' => 'nullable|string',
        ]);

        $deck = Auth::user()->decks()->create($validated);

        return response()->json($deck, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        return $deck->load(['cards' => function ($query) {
            $query->withPivot('quantity', 'is_sideboard');
        }]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'nullable|string|in:standard,modern,pioneer,legacy,vintage,commander,brawl,pauper,casual',
            'description' => 'nullable|string',
        ]);

        $deck->update($validated);

        return response()->json($deck);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $deck->delete();

        return response()->noContent();
    }

    /**
     * Add a card to the deck.
     */
    public function addCard(Request $request, Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'card_id' => 'nullable|exists:cards,id',
            'scryfall_id' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'is_sideboard' => 'boolean',
        ]);

        $cardId = $validated['card_id'] ?? null;

        if (!$cardId && !empty($validated['scryfall_id'])) {
            // Find or create card from Scryfall
            $scryfallService = app(\App\Services\ScryfallService::class);
            $cardData = $scryfallService->findCardById($validated['scryfall_id']);
            
            if ($cardData) {
                $card = \App\Models\Card::updateOrCreate(
                    ['scryfall_id' => $cardData['scryfall_id']],
                    [
                        'name' => $cardData['name'],
                        'set_code' => $cardData['set_code'],
                        'set_name' => $cardData['set_name'],
                        'collector_number' => $cardData['collector_number'],
                        'rarity' => $cardData['rarity'],
                        'mana_cost' => $cardData['mana_cost'],
                        'color_identity' => $cardData['color_identity'] ?? [],
                        'type_line' => $cardData['type_line'],
                        'image_uri' => $cardData['image_uri'],
                    ]
                );
                $cardId = $card->id;
            }
        }

        if (!$cardId) {
            return response()->json(['message' => 'Card not found'], 422);
        }

        $deck->cards()->syncWithoutDetaching([
            $cardId => [
                'quantity' => $validated['quantity'],
                'is_sideboard' => $validated['is_sideboard'] ?? false,
            ]
        ]);

        return response()->json(['message' => 'Card added successfully']);
    }

    /**
     * Remove a card from the deck.
     */
    public function removeCard(Request $request, Deck $deck, $cardId)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $deck->cards()->detach($cardId);

        return response()->json(['message' => 'Card removed successfully']);
    }
}
