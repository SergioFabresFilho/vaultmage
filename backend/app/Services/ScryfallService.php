<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                ->withHeaders([
                    'User-Agent' => 'VaultMage/1.0 (contact@vaultmage.app)',
                    'Accept'     => 'application/json;q=0.9,*/*;q=0.8',
                ])
                ->get('/cards/named', $params);

            if ($response->status() === 404) {
                Log::warning('Scryfall card not found', ['name' => $name, 'set_code' => $setCode]);
                throw new RuntimeException("Card not found: \"{$name}\"" . ($setCode ? " in set {$setCode}" : ''));
            }

            if (! $response->ok()) {
                $detail = $response->json('details') ?? $response->json('message') ?? 'Unknown Scryfall error';
                Log::error('Scryfall API request failed', ['name' => $name, 'set_code' => $setCode, 'status' => $response->status(), 'detail' => $detail]);
                throw new RuntimeException("Scryfall error ({$response->status()}): {$detail}");
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
        });
    }
}
