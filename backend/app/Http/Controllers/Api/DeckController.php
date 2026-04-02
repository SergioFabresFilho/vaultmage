<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class DeckController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        $decks = $user->decks()
            ->withSum('cards as cards_sum_quantity', 'deck_cards.quantity')
            ->latest()
            ->get();

        // Compute price totals per deck in one query
        // total_price  = sum(price_usd * quantity) for all cards in deck
        // missing_price = sum(price_usd * quantity) for cards the user does NOT own
        $deckIds = $decks->pluck('id');

        $totals = \Illuminate\Support\Facades\DB::table('deck_cards')
            ->join('cards', 'deck_cards.card_id', '=', 'cards.id')
            ->whereIn('deck_cards.deck_id', $deckIds)
            ->whereNotNull('cards.price_usd')
            ->selectRaw('
                deck_cards.deck_id,
                SUM(cards.price_usd * deck_cards.quantity) as total_price,
                SUM(CASE WHEN collection.quantity IS NULL OR collection.quantity = 0
                         THEN cards.price_usd * deck_cards.quantity ELSE 0 END) as missing_price
            ')
            ->leftJoin(
                \Illuminate\Support\Facades\DB::raw('(SELECT card_id, quantity FROM collection_cards WHERE user_id = ' . $user->id . ') AS collection'),
                'cards.id', '=', 'collection.card_id'
            )
            ->groupBy('deck_cards.deck_id')
            ->get()
            ->keyBy('deck_id');

        $commanderImages = \Illuminate\Support\Facades\DB::table('deck_cards')
            ->join('cards', 'deck_cards.card_id', '=', 'cards.id')
            ->whereIn('deck_cards.deck_id', $deckIds)
            ->where('deck_cards.is_commander', true)
            ->whereNotNull('cards.image_uri')
            ->select('deck_cards.deck_id', 'cards.image_uri as commander_image_uri')
            ->get()
            ->keyBy('deck_id');

        return $decks->map(function ($deck) use ($totals, $commanderImages) {
            $t = $totals->get($deck->id);
            $deck->total_price          = $t ? round((float) $t->total_price, 2) : null;
            $deck->missing_price        = $t ? round((float) $t->missing_price, 2) : null;
            $deck->commander_image_uri  = $commanderImages->get($deck->id)?->commander_image_uri ?? null;
            return $deck;
        });
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'nullable|string|in:standard,modern,pioneer,legacy,vintage,commander,edh,brawl,pauper,casual',
            'description' => 'nullable|string',
            'color_identity' => 'nullable|array',
            'color_identity.*' => 'string|in:W,U,B,R,G',
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
            $query->withPivot('quantity', 'is_sideboard')
                  ->select('cards.id', 'cards.name', 'cards.type_line', 'cards.mana_cost',
                           'cards.image_uri', 'cards.color_identity', 'cards.rarity',
                           'cards.cmc', 'cards.price_usd');
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
            'format' => 'nullable|string|in:standard,modern,pioneer,legacy,vintage,commander,edh,brawl,pauper,casual',
            'description' => 'nullable|string',
            'color_identity' => 'nullable|array',
            'color_identity.*' => 'string|in:W,U,B,R,G',
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

        // Color identity validation: skip if deck has no color identity set
        $deckColors = $deck->color_identity ?? [];
        if (!empty($deckColors)) {
            $cardToCheck = null;
            if (!empty($validated['card_id'])) {
                $cardToCheck = \App\Models\Card::find($validated['card_id']);
            } elseif (!empty($validated['scryfall_id'])) {
                $cardToCheck = \App\Models\Card::where('scryfall_id', $validated['scryfall_id'])->first();
            }

            if ($cardToCheck && !empty($cardToCheck->color_identity)) {
                $offendingColors = array_diff($cardToCheck->color_identity, $deckColors);
                if (!empty($offendingColors)) {
                    return response()->json([
                        'message' => "Color identity mismatch: {$cardToCheck->name} contains " . implode(', ', $offendingColors) . " which is outside the deck's color identity.",
                    ], 422);
                }
            }
        }

        $cardId = $validated['card_id'] ?? null;

        if (!$cardId && !empty($validated['scryfall_id'])) {
            // Find or create card from Scryfall
            $scryfallService = app(\App\Services\ScryfallService::class);
            try {
                $cardData = $scryfallService->findCardById($validated['scryfall_id']);
            } catch (RuntimeException) {
                $cardData = null;
            }
            
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
                        'oracle_text' => $cardData['oracle_text'] ?? null,
                        'cmc' => $cardData['cmc'] ?? null,
                        'color_identity' => $cardData['color_identity'] ?? [],
                        'legalities' => $cardData['legalities'] ?? [],
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
     * Promote a draft deck to a real deck.
     */
    public function validate(Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $deck->update(['is_draft' => false]);

        return response()->json($deck);
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
