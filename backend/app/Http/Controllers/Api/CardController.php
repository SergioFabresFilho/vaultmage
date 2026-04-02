<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
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
        $query = trim((string) $request->query('q', ''));
        $format = strtolower((string) $request->query('format', ''));
        $commanderOnly = $request->boolean('commander_only');

        if ($query === '') {
            return response()->json([]);
        }

        $localResults = $this->searchLocalCards($query, $format, $commanderOnly);
        $remoteResults = $this->searchRemoteCards($query, $format, $commanderOnly);

        $results = collect($localResults)
            ->concat($remoteResults)
            ->unique(fn (array $card) => $card['scryfall_id'] ?? strtolower($card['name'] ?? ''))
            ->values()
            ->take(30)
            ->all();

        return response()->json($results);
    }

    private function searchLocalCards(string $query, string $format, bool $commanderOnly): array
    {
        $cards = Card::query()
            ->where('name', 'like', '%' . $query . '%')
            ->when($format !== '', function ($builder) use ($format) {
                $builder->where("legalities->{$format}", 'legal');
            })
            ->when($commanderOnly, function ($builder) {
                $builder->where(function ($query) {
                    $query
                        ->where('oracle_text', 'like', '%can be your commander%')
                        ->orWhere(function ($subQuery) {
                            $subQuery
                                ->where('type_line', 'like', '%Legendary%')
                                ->where(function ($typeQuery) {
                                    $typeQuery
                                        ->where('type_line', 'like', '%Creature%')
                                        ->orWhere('type_line', 'like', '%Planeswalker%');
                                });
                        });
                });
            })
            ->orderByRaw(
                'case when lower(name) = ? then 0 when lower(name) like ? then 1 else 2 end',
                [strtolower($query), strtolower($query) . '%']
            )
            ->orderBy('name')
            ->limit(15)
            ->get();

        return $cards
            ->map(fn (Card $card) => $this->mapLocalCard($card))
            ->all();
    }

    private function searchRemoteCards(string $query, string $format, bool $commanderOnly): array
    {
        $searchQuery = $query;

        if ($commanderOnly) {
            $searchQuery = trim(match ($format) {
                'brawl' => "{$query} legal:brawl (type:legendary type:creature or oracle:\"can be your commander\")",
                default => "{$query} is:commander legal:commander",
            });
        } elseif ($format !== '') {
            $searchQuery = trim("{$query} legal:{$format}");
        }

        return $this->scryfall->search($searchQuery);
    }

    private function mapLocalCard(Card $card): array
    {
        return [
            'id'               => $card->id,
            'scryfall_id'      => $card->scryfall_id,
            'name'             => $card->name,
            'set_code'         => $card->set_code,
            'set_name'         => $card->set_name,
            'collector_number' => $card->collector_number,
            'rarity'           => $card->rarity,
            'mana_cost'        => $card->mana_cost,
            'oracle_text'      => $card->oracle_text,
            'cmc'              => $card->cmc,
            'color_identity'   => $card->color_identity,
            'legalities'       => $card->legalities,
            'type_line'        => $card->type_line,
            'image_uri'        => $card->image_uri,
            'price_usd'        => $card->price_usd,
        ];
    }
}
