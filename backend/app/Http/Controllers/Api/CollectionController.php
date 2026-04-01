<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\CardOcrParser;
use App\Services\CloudVisionService;
use App\Services\ScryfallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CollectionController extends Controller
{
    public function __construct(
        private CloudVisionService $vision,
        private CardOcrParser $parser,
        private ScryfallService $scryfall,
    ) {}

    /**
     * Scan a card image and return the matched card data for confirmation.
     * Does not add to collection — the mobile app should show a preview first.
     *
     * POST /api/collection/scan
     * Body: { image: "<base64>" }
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'nullable|string',
            'images' => 'nullable|array|min:1',
            'images.*' => 'string',
        ]);

        $images = $validated['images'] ?? null;
        if ($images === null && ! empty($validated['image'])) {
            $images = [$validated['image']];
        }

        if ($images === null || $images === []) {
            throw ValidationException::withMessages(['image' => 'A scan image is required.']);
        }

        $startedAt = microtime(true);
        $ocrStartedAt = microtime(true);

        try {
            $ocrText = $this->vision->extractCardTexts($images);
        } catch (RuntimeException $e) {
            Log::error('CloudVision OCR failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['image' => 'Could not process image: ' . $e->getMessage()]);
        }

        $ocrDurationMs = (int) round((microtime(true) - $ocrStartedAt) * 1000);

        if (empty($ocrText)) {
            Log::warning('CloudVision returned no text from scan image');
            throw ValidationException::withMessages(['image' => 'No text could be extracted from this image.']);
        }

        $parseStartedAt = microtime(true);
        ['name' => $name, 'set_code' => $setCode, 'collector_number' => $collectorNumber] = $this->parser->parse($ocrText);
        $parseDurationMs = (int) round((microtime(true) - $parseStartedAt) * 1000);

        if (empty($name)) {
            Log::warning('Card scan OCR produced no card name', ['ocr_text' => $ocrText]);
            throw ValidationException::withMessages(['image' => 'Could not identify a card name from the image. Try better lighting or angle.']);
        }

        Log::info('Card scan OCR parsed', ['name' => $name, 'set_code' => $setCode, 'collector_number' => $collectorNumber]);

        try {
            ['card' => $cardData, 'lookup_meta' => $lookupMeta] = $this->resolveScannedCard($name, $setCode, $collectorNumber);
        } catch (RuntimeException $e) {
            Log::warning('Scryfall card lookup failed', ['name' => $name, 'set_code' => $setCode, 'collector_number' => $collectorNumber, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        Log::info('Card scan completed', [
            'name' => $cardData['name'] ?? $name,
            'set_code' => $cardData['set_code'] ?? $setCode,
            'collector_number' => $cardData['collector_number'] ?? $collectorNumber,
            'ocr_ms' => $ocrDurationMs,
            'parse_ms' => $parseDurationMs,
            'lookup_source' => $lookupMeta['source'],
            'lookup_ms' => $lookupMeta['lookup_ms'],
            'local_lookup_ms' => $lookupMeta['local_lookup_ms'],
            'scryfall_ms' => $lookupMeta['scryfall_ms'],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($cardData);
    }

    /**
     * Add a card to the authenticated user's collection.
     *
     * POST /api/collection
     * Body: { scryfall_id: "...", foil: false }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'scryfall_id' => 'required|uuid',
            'foil'        => 'boolean',
        ]);

        $cardData = $this->fetchByScryfallId($request->input('scryfall_id'));

        $card = Card::firstOrCreate(
            ['scryfall_id' => $cardData['scryfall_id']],
            $cardData,
        );

        $user = $request->user();

        $foil = $request->boolean('foil');

        $existing = $user->collection()
            ->wherePivot('card_id', $card->id)
            ->wherePivot('foil', $foil)
            ->first();

        if ($existing) {
            $user->collection()->updateExistingPivot($card->id, [
                'quantity' => $existing->pivot->quantity + 1,
            ]);
        } else {
            $user->collection()->attach($card->id, [
                'quantity' => 1,
                'foil'     => $foil,
            ]);
        }

        return response()->json($card, 201);
    }

    /**
     * List the authenticated user's collection.
     *
     * GET /api/collection
     */
    public function index(Request $request): JsonResponse
    {
        $cards = $request->user()->collection()->get();

        return response()->json($cards);
    }

    /**
     * Update the quantity of a card in the authenticated user's collection.
     *
     * PATCH /api/collection/{card}
     * Body: { quantity: N, foil: false }
     */
    public function update(Request $request, Card $card): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:0',
            'foil'     => 'boolean',
        ]);

        $user     = $request->user();
        $foil     = $request->boolean('foil');
        $quantity = $request->integer('quantity');

        if ($quantity <= 0) {
            \DB::table('collection_cards')
                ->where('user_id', $user->id)
                ->where('card_id', $card->id)
                ->where('foil', $foil)
                ->delete();

            return response()->json(['message' => 'Card removed from collection']);
        }

        \DB::table('collection_cards')
            ->where('user_id', $user->id)
            ->where('card_id', $card->id)
            ->where('foil', $foil)
            ->update(['quantity' => $quantity, 'updated_at' => now()]);

        return response()->json(['quantity' => $quantity]);
    }

    /**
     * Search the authenticated user's collection by card name.
     *
     * GET /api/collection/search?q=...
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (empty($query)) {
            return response()->json([]);
        }

        $cards = $request->user()->collection()
            ->where('name', 'LIKE', "%{$query}%")
            ->get();

        return response()->json($cards);
    }

    private function fetchByScryfallId(string $scryfallId): array
    {
        return $this->scryfall->findCardById($scryfallId);
    }

    private function resolveScannedCard(string $name, ?string $setCode, ?string $collectorNumber): array
    {
        $lookupStartedAt = microtime(true);
        $localLookupMs = 0;
        $scryfallMs = 0;

        if ($setCode && $collectorNumber) {
            $localStartedAt = microtime(true);
            $localCard = Card::query()
                ->where('set_code', strtoupper($setCode))
                ->where('collector_number', $collectorNumber)
                ->first();
            $localLookupMs += (int) round((microtime(true) - $localStartedAt) * 1000);

            if ($localCard) {
                return [
                    'card' => $localCard->toArray(),
                    'lookup_meta' => [
                        'source' => 'local_set_number',
                        'lookup_ms' => (int) round((microtime(true) - $lookupStartedAt) * 1000),
                        'local_lookup_ms' => $localLookupMs,
                        'scryfall_ms' => $scryfallMs,
                    ],
                ];
            }

            try {
                $scryfallStartedAt = microtime(true);
                $card = $this->scryfall->findCardBySetAndNumber($setCode, $collectorNumber);
                $scryfallMs += (int) round((microtime(true) - $scryfallStartedAt) * 1000);

                return [
                    'card' => $card,
                    'lookup_meta' => [
                        'source' => 'scryfall_set_number',
                        'lookup_ms' => (int) round((microtime(true) - $lookupStartedAt) * 1000),
                        'local_lookup_ms' => $localLookupMs,
                        'scryfall_ms' => $scryfallMs,
                    ],
                ];
            } catch (RuntimeException) {
                $scryfallMs += (int) round((microtime(true) - $scryfallStartedAt) * 1000);
                // Fall through to name-based lookup.
            }
        }

        $localStartedAt = microtime(true);
        $localCard = Card::query()
            ->when($setCode, fn ($query) => $query->where('set_code', strtoupper($setCode)))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        $localLookupMs += (int) round((microtime(true) - $localStartedAt) * 1000);

        if ($localCard) {
            return [
                'card' => $localCard->toArray(),
                'lookup_meta' => [
                    'source' => $setCode ? 'local_name_set' : 'local_name',
                    'lookup_ms' => (int) round((microtime(true) - $lookupStartedAt) * 1000),
                    'local_lookup_ms' => $localLookupMs,
                    'scryfall_ms' => $scryfallMs,
                ],
            ];
        }

        $scryfallStartedAt = microtime(true);
        $card = $this->scryfall->findCard($name, $setCode);
        $scryfallMs += (int) round((microtime(true) - $scryfallStartedAt) * 1000);

        return [
            'card' => $card,
            'lookup_meta' => [
                'source' => $setCode ? 'scryfall_name_set' : 'scryfall_name',
                'lookup_ms' => (int) round((microtime(true) - $lookupStartedAt) * 1000),
                'local_lookup_ms' => $localLookupMs,
                'scryfall_ms' => $scryfallMs,
            ],
        ];
    }
}
