<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScryfallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function __construct(
        private ScryfallService $scryfall,
    ) {}

    /**
     * Search for cards via Scryfall.
     *
     * GET /api/cards/search?q=...
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (empty($query)) {
            return response()->json([]);
        }

        $results = $this->scryfall->search($query);

        return response()->json($results);
    }
}
