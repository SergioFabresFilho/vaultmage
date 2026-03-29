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
        $request->validate(['image' => 'required|string']);

        try {
            $ocrText = $this->vision->extractText($request->input('image'));
        } catch (RuntimeException $e) {
            Log::error('CloudVision OCR failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['image' => 'Could not process image: ' . $e->getMessage()]);
        }

        if (empty($ocrText)) {
            Log::warning('CloudVision returned no text from scan image');
            throw ValidationException::withMessages(['image' => 'No text could be extracted from this image.']);
        }

        ['name' => $name, 'set_code' => $setCode] = $this->parser->parse($ocrText);

        if (empty($name)) {
            Log::warning('Card scan OCR produced no card name', ['ocr_text' => $ocrText]);
            throw ValidationException::withMessages(['image' => 'Could not identify a card name from the image. Try better lighting or angle.']);
        }

        Log::info('Card scan OCR parsed', ['name' => $name, 'set_code' => $setCode]);

        try {
            $cardData = $this->scryfall->findCard($name, $setCode);
        } catch (RuntimeException $e) {
            Log::warning('Scryfall card lookup failed', ['name' => $name, 'set_code' => $setCode, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

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
            'scryfall_id' => 'required|string',
            'foil'        => 'boolean',
        ]);

        try {
            $cardData = $this->scryfall->findCard($request->input('scryfall_id'));
        } catch (RuntimeException $e) {
            // Fall back to direct Scryfall ID lookup if findCard by name fails
            $cardData = $this->fetchByScryfallId($request->input('scryfall_id'));
        }

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

    private function fetchByScryfallId(string $scryfallId): array
    {
        $response = \Illuminate\Support\Facades\Http::baseUrl('https://api.scryfall.com')
            ->withHeaders([
                'User-Agent' => 'VaultMage/1.0 (contact@vaultmage.app)',
                'Accept'     => 'application/json;q=0.9,*/*;q=0.8',
            ])
            ->get("/cards/{$scryfallId}");

        if (! $response->ok()) {
            throw new RuntimeException("Card not found for Scryfall ID: {$scryfallId}");
        }

        $data = $response->json();

        return [
            'scryfall_id'      => $data['id'],
            'name'             => $data['name'],
            'set_code'         => strtoupper($data['set']),
            'set_name'         => $data['set_name'],
            'collector_number' => $data['collector_number'],
            'rarity'           => $data['rarity'],
            'mana_cost'        => $data['mana_cost'] ?? null,
            'color_identity'   => $data['color_identity'],
            'type_line'        => $data['type_line'],
            'image_uri'        => $data['image_uris']['normal'] ?? $data['card_faces'][0]['image_uris']['normal'] ?? null,
        ];
    }
}
