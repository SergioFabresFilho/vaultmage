<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ScryfallService
{
    private const BASE_URL = 'https://api.scryfall.com';

    /**
     * Find a card by name (fuzzy) and optional set code.
     *
     * @return array{scryfall_id:string, name:string, set_code:string, set_name:string, collector_number:string, rarity:string, mana_cost:string|null, type_line:string, image_uri:string|null}
     *
     * @throws RuntimeException when card is not found
     */
    public function findCard(string $name, ?string $setCode = null): array
    {
        $cacheKey = 'scryfall:' . strtolower($name) . ($setCode ? ':' . strtolower($setCode) : '');

        return Cache::rememberForever($cacheKey, function () use ($name, $setCode) {
            $params = ['fuzzy' => $name];

            if ($setCode) {
                $params['set'] = strtolower($setCode);
            }

            $response = Http::baseUrl(self::BASE_URL)
                ->get('/cards/named', $params);

            if ($response->status() === 404) {
                throw new RuntimeException("Card not found: \"{$name}\"" . ($setCode ? " in set {$setCode}" : ''));
            }

            $response->throw();

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
        });
    }
}
