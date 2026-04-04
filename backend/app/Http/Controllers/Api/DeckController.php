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
            ->selectRaw('
                deck_cards.deck_id,
                SUM(CASE WHEN cards.price_usd IS NOT NULL
                         THEN cards.price_usd * deck_cards.quantity ELSE 0 END) as total_price,
                SUM(CASE WHEN collection.quantity IS NULL OR collection.quantity = 0
                         THEN COALESCE(cards.price_usd, 0) * deck_cards.quantity
                         WHEN collection.quantity < deck_cards.quantity
                         THEN COALESCE(cards.price_usd, 0) * (deck_cards.quantity - collection.quantity)
                         ELSE 0 END) as missing_price,
                SUM(CASE WHEN collection.quantity IS NULL OR collection.quantity = 0
                         THEN 0
                         WHEN collection.quantity < deck_cards.quantity
                         THEN collection.quantity
                         ELSE deck_cards.quantity END) as owned_cards_count,
                SUM(CASE WHEN collection.quantity IS NULL OR collection.quantity = 0
                         THEN deck_cards.quantity
                         WHEN collection.quantity < deck_cards.quantity
                         THEN deck_cards.quantity - collection.quantity
                         ELSE 0 END) as missing_cards_count
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
            $deck->owned_cards_count    = $t ? (int) $t->owned_cards_count : 0;
            $deck->missing_cards_count  = $t ? (int) $t->missing_cards_count : 0;
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

        $deck->load(['cards' => function ($query) {
            $query->withPivot('quantity', 'is_sideboard', 'is_commander')
                  ->select('cards.id', 'cards.name', 'cards.set_name', 'cards.type_line', 'cards.mana_cost',
                           'cards.image_uri', 'cards.color_identity', 'cards.rarity',
                           'cards.cmc', 'cards.price_usd');
        }]);

        $ownedQuantities = Auth::user()
            ->collection()
            ->pluck('collection_cards.quantity', 'cards.id');

        $deck->cards->each(function ($card) use ($ownedQuantities) {
            $required = (int) $card->pivot->quantity;
            $owned = (int) ($ownedQuantities[$card->id] ?? 0);

            $card->setAttribute('quantity_required', $required);
            $card->setAttribute('owned_quantity', $owned);
            $card->setAttribute('missing_quantity', max(0, $required - $owned));
        });

        return $deck;
    }

    public function buyList(Deck $deck)
    {
        if ($deck->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = request()->validate([
            'budget' => 'nullable|numeric|min:0',
        ]);

        $budget = array_key_exists('budget', $validated)
            ? round((float) $validated['budget'], 2)
            : null;

        $deck->load(['cards' => function ($query) {
            $query->withPivot('quantity', 'is_sideboard', 'is_commander')
                ->select(
                    'cards.id',
                    'cards.name',
                    'cards.set_name',
                    'cards.type_line',
                    'cards.mana_cost',
                    'cards.image_uri',
                    'cards.rarity',
                    'cards.price_usd'
                );
        }]);

        $ownedQuantities = Auth::user()
            ->collection()
            ->pluck('collection_cards.quantity', 'cards.id');

        $items = $deck->cards
            ->map(function ($card) use ($ownedQuantities) {
                $required = (int) $card->pivot->quantity;
                $owned = (int) ($ownedQuantities[$card->id] ?? 0);
                $missing = max(0, $required - $owned);

                if ($missing <= 0) {
                    return null;
                }

                $unitPrice = $card->price_usd !== null ? (float) $card->price_usd : null;

                return [
                    'card_id' => $card->id,
                    'name' => $card->name,
                    'set_name' => $card->set_name,
                    'type_line' => $card->type_line,
                    'mana_cost' => $card->mana_cost,
                    'image_uri' => $card->image_uri,
                    'rarity' => $card->rarity,
                    'quantity_required' => $required,
                    'owned_quantity' => $owned,
                    'missing_quantity' => $missing,
                    'price_usd' => $unitPrice,
                    'line_total' => $unitPrice !== null ? round($unitPrice * $missing, 2) : null,
                    'is_commander' => (bool) $card->pivot->is_commander,
                    'is_sideboard' => (bool) $card->pivot->is_sideboard,
                    'priority' => (bool) $card->pivot->is_sideboard ? 'optional' : 'must-buy',
                    'category' => (bool) $card->pivot->is_commander
                        ? 'commander'
                        : ((bool) $card->pivot->is_sideboard ? 'sideboard' : 'mainboard'),
                ];
            })
            ->filter()
            ->sortBy(fn (array $item) => [
                $item['is_commander'] ? 0 : ($item['is_sideboard'] ? 2 : 1),
                $item['line_total'] === null ? 1 : 0,
                $item['line_total'] ?? PHP_FLOAT_MAX,
                -1 * $item['missing_quantity'],
            ])
            ->values();

        $pricedItems = $items->filter(fn (array $item) => $item['line_total'] !== null);
        $mustBuyItems = $items->filter(fn (array $item) => $item['priority'] === 'must-buy')->values();
        $optionalItems = $items->filter(fn (array $item) => $item['priority'] === 'optional')->values();

        $recommended = collect();
        $deferred = collect();
        $budgetRemaining = $budget;

        foreach ($mustBuyItems as $item) {
            if ($budgetRemaining === null || $item['line_total'] === null) {
                $recommended->push($item);
                continue;
            }

            if ($item['line_total'] <= $budgetRemaining) {
                $recommended->push($item);
                $budgetRemaining = round($budgetRemaining - $item['line_total'], 2);
            } else {
                $deferred->push($item);
            }
        }

        foreach ($optionalItems as $item) {
            if ($budgetRemaining === null) {
                $deferred->push($item);
                continue;
            }

            if ($item['line_total'] === null) {
                $deferred->push($item);
                continue;
            }

            if ($item['line_total'] <= $budgetRemaining) {
                $recommended->push($item);
                $budgetRemaining = round($budgetRemaining - $item['line_total'], 2);
            } else {
                $deferred->push($item);
            }
        }

        $recommendedIds = $recommended->pluck('card_id')->all();
        $grouped = [
            'must_buy' => $recommended->filter(fn (array $item) => $item['priority'] === 'must-buy')->values()->all(),
            'optional' => $recommended->filter(fn (array $item) => $item['priority'] === 'optional')->values()->all(),
            'deferred' => $items->filter(fn (array $item) => ! in_array($item['card_id'], $recommendedIds, true))->values()->all(),
        ];
        $recommendedPriced = $recommended->filter(fn (array $item) => $item['line_total'] !== null);

        return response()->json([
            'deck_id' => $deck->id,
            'deck_name' => $deck->name,
            'format' => $deck->format,
            'items' => $items->all(),
            'missing_cards_count' => $items->sum('missing_quantity'),
            'estimated_total' => round((float) $pricedItems->sum('line_total'), 2),
            'priced_items_count' => $pricedItems->count(),
            'unpriced_items_count' => $items->count() - $pricedItems->count(),
            'budget' => $budget,
            'budget_remaining' => $budgetRemaining,
            'recommended_total' => round((float) $recommendedPriced->sum('line_total'), 2),
            'groups' => $grouped,
        ]);
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
