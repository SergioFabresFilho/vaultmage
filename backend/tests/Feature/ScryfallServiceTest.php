<?php

namespace Tests\Feature;

use App\Services\ScryfallService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScryfallServiceTest extends TestCase
{
    private ScryfallService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScryfallService();
    }

    public function test_it_finds_card_by_id()
    {
        $uuid = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';
        Http::fake([
            "https://api.scryfall.com/cards/{$uuid}" => Http::response([
                'id' => $uuid,
                'name' => 'Opt',
                'set' => 'm21',
                'set_name' => 'Core Set 2021',
                'collector_number' => '59',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'color_identity' => ['U'],
                'type_line' => 'Instant',
                'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
            ], 200),
        ]);

        $result = $this->service->findCardById($uuid);

        $this->assertEquals('Opt', $result['name']);
        $this->assertEquals($uuid, $result['scryfall_id']);
    }

    public function test_it_finds_card_by_set_and_number()
    {
        Http::fake([
            'https://api.scryfall.com/cards/m21/59' => Http::response([
                'id' => 'uuid-123',
                'name' => 'Opt',
                'set' => 'm21',
                'set_name' => 'Core Set 2021',
                'collector_number' => '59',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'color_identity' => ['U'],
                'type_line' => 'Instant',
                'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
            ], 200),
        ]);

        $result = $this->service->findCardBySetAndNumber('m21', '59');

        $this->assertEquals('Opt', $result['name']);
        $this->assertEquals('M21', $result['set_code']);
        $this->assertEquals('uuid-123', $result['scryfall_id']);
    }

    public function test_it_finds_card_by_set_and_number_not_found()
    {
        Http::fake([
            'https://api.scryfall.com/cards/m21/999' => Http::response(['details' => 'Not Found'], 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Card not found: m21 #999');

        $this->service->findCardBySetAndNumber('m21', '999');
    }

    public function test_it_finds_card_by_name_fuzzy()
    {
        Http::fake([
            'https://api.scryfall.com/cards/named?fuzzy=Opt' => Http::response([
                'id' => 'uuid-123',
                'name' => 'Opt',
                'set' => 'm21',
                'set_name' => 'Core Set 2021',
                'collector_number' => '59',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'color_identity' => ['U'],
                'type_line' => 'Instant',
                'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
            ], 200),
        ]);

        $result = $this->service->findCard('Opt');

        $this->assertEquals('Opt', $result['name']);
    }

    public function test_it_finds_card_by_name_and_set()
    {
        Http::fake([
            'https://api.scryfall.com/cards/named?fuzzy=Opt&set=m21' => Http::response([
                'id' => 'uuid-123',
                'name' => 'Opt',
                'set' => 'm21',
                'set_name' => 'Core Set 2021',
                'collector_number' => '59',
                'rarity' => 'common',
                'mana_cost' => '{U}',
                'color_identity' => ['U'],
                'type_line' => 'Instant',
                'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
            ], 200),
        ]);

        $result = $this->service->findCard('Opt', 'm21');

        $this->assertEquals('Opt', $result['name']);
        $this->assertEquals('M21', $result['set_code']);
    }

    public function test_it_searches_cards()
    {
        Http::fake([
            'https://api.scryfall.com/cards/search?q=Opt' => Http::response([
                'data' => [
                    [
                        'id' => 'uuid-123',
                        'name' => 'Opt',
                        'set' => 'm21',
                        'set_name' => 'Core Set 2021',
                        'collector_number' => '59',
                        'rarity' => 'common',
                        'mana_cost' => '{U}',
                        'color_identity' => ['U'],
                        'type_line' => 'Instant',
                        'image_uris' => ['normal' => 'https://example.com/opt.jpg'],
                    ]
                ]
            ], 200),
        ]);

        $results = $this->service->search('Opt');

        $this->assertCount(1, $results);
        $this->assertEquals('Opt', $results[0]['name']);
    }

    public function test_it_searches_cards_not_found()
    {
        Http::fake([
            'https://api.scryfall.com/cards/search?q=NonExistent' => Http::response(['details' => 'Not Found'], 404),
        ]);

        $results = $this->service->search('NonExistent');

        $this->assertCount(0, $results);
    }

    public function test_it_handles_generic_api_error()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scryfall error (500)');

        $this->service->findCard('Opt');
    }

    public function test_it_handles_404_not_found()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Card not found');

        $this->service->findCard('NonExistentCard');
    }
}
