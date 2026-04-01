<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CloudVisionService;
use App\Services\ScryfallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_it_lists_the_collection()
    {
        $card = Card::factory()->create();
        $this->user->collection()->attach($card->id, ['quantity' => 2, 'foil' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/collection');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $card->id)
            ->assertJsonPath('0.pivot.quantity', 2);
    }

    public function test_it_adds_a_card_to_the_collection()
    {
        $uuid = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';
        $cardData = [
            'scryfall_id'      => $uuid,
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ];

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($uuid, $cardData) {
            $mock->shouldReceive('findCardById')
                ->with($uuid)
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection', [
                'scryfall_id' => $uuid,
                'foil'        => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Opt');

        $this->assertDatabaseHas('cards', ['scryfall_id' => $uuid]);
        $this->assertEquals(1, $this->user->collection()->count());
        $this->assertEquals(1, $this->user->collection()->first()->pivot->quantity);
    }

    public function test_it_increments_quantity_if_card_already_exists_in_collection()
    {
        $uuid = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';
        $card = Card::factory()->create(['scryfall_id' => $uuid]);
        $this->user->collection()->attach($card->id, ['quantity' => 1, 'foil' => false]);

        $cardData = [
            'scryfall_id'      => $uuid,
            'name'             => $card->name,
            'set_code'         => $card->set_code,
            'set_name'         => $card->set_name,
            'collector_number' => $card->collector_number,
            'rarity'           => $card->rarity,
            'mana_cost'        => $card->mana_cost,
            'color_identity'   => $card->color_identity,
            'type_line'        => $card->type_line,
            'image_uri'        => $card->image_uri,
        ];

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($uuid, $cardData) {
            $mock->shouldReceive('findCardById')
                ->with($uuid)
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection', [
                'scryfall_id' => $uuid,
                'foil'        => false,
            ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $this->user->collection()->count());
        $this->assertEquals(2, $this->user->collection()->first()->pivot->quantity);
    }

    public function test_it_adds_a_card_as_foil_if_requested()
    {
        $uuid = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';
        $cardData = [
            'scryfall_id'      => $uuid,
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ];

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($uuid, $cardData) {
            $mock->shouldReceive('findCardById')
                ->with($uuid)
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection', [
                'scryfall_id' => $uuid,
                'foil'        => true,
            ]);

        $response->assertStatus(201);
        $this->assertTrue((bool)$this->user->collection()->first()->pivot->foil);
    }

    public function test_it_falls_back_to_direct_lookup_if_find_card_fails()
    {
        $uuid = 'f9b8a159-5e58-4432-8ecd-62f39afa96da';
        $cardData = [
            'scryfall_id'      => $uuid,
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ];

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($uuid, $cardData) {
            $mock->shouldReceive('findCardById')
                ->once()
                ->with($uuid)
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection', [
                'scryfall_id' => $uuid,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('cards', ['scryfall_id' => $uuid]);
    }

    public function test_it_scans_a_card_with_fallback_if_set_number_fails()
    {
        $base64Image = base64_encode('fake-image-content');
        $ocrText = "Opt\nInstant\nM21 · 059/274";
        $cardData = [
            'scryfall_id'      => 'f9b8a159-5e58-4432-8ecd-62f39afa96da',
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ];

        $this->mock(CloudVisionService::class, function (MockInterface $mock) use ($ocrText) {
            $mock->shouldReceive('extractCardTexts')->andReturn($ocrText);
        });

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($cardData) {
            $mock->shouldReceive('findCardBySetAndNumber')
                ->with('M21', '059')
                ->andThrow(new \RuntimeException('Not found'));

            $mock->shouldReceive('findCard')
                ->with('Opt', 'M21')
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection/scan', [
                'image' => $base64Image,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Opt');
    }

    public function test_it_scans_a_card()
    {
        $base64Image = base64_encode('fake-image-content');
        $ocrText = "Opt\nInstant\nM21 · 059/274";
        $cardData = [
            'scryfall_id'      => 'f9b8a159-5e58-4432-8ecd-62f39afa96da',
            'name'             => 'Opt',
            'set_code'         => 'M21',
            'set_name'         => 'Core Set 2021',
            'collector_number' => '059',
            'rarity'           => 'common',
            'mana_cost'        => '{U}',
            'color_identity'   => ['U'],
            'type_line'        => 'Instant',
            'image_uri'        => 'https://example.com/opt.jpg',
        ];

        $this->mock(CloudVisionService::class, function (MockInterface $mock) use ($ocrText) {
            $mock->shouldReceive('extractCardTexts')
                ->andReturn($ocrText);
        });

        $this->mock(ScryfallService::class, function (MockInterface $mock) use ($cardData) {
            $mock->shouldReceive('findCardBySetAndNumber')
                ->with('M21', '059')
                ->andReturn($cardData);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection/scan', [
                'image' => $base64Image,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Opt')
            ->assertJsonPath('scryfall_id', 'f9b8a159-5e58-4432-8ecd-62f39afa96da');
    }

    public function test_it_scans_a_card_from_local_database_without_scryfall_lookup()
    {
        $base64Image = base64_encode('fake-image-content');
        $ocrText = "Opt\nInstant\nM21 · 059/274";
        $card = Card::factory()->create([
            'scryfall_id' => 'f9b8a159-5e58-4432-8ecd-62f39afa96da',
            'name' => 'Opt',
            'set_code' => 'M21',
            'set_name' => 'Core Set 2021',
            'collector_number' => '059',
            'rarity' => 'common',
            'mana_cost' => '{U}',
            'type_line' => 'Instant',
            'image_uri' => 'https://example.com/opt.jpg',
        ]);

        $this->mock(CloudVisionService::class, function (MockInterface $mock) use ($ocrText) {
            $mock->shouldReceive('extractCardTexts')
                ->andReturn($ocrText);
        });

        $this->mock(ScryfallService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('findCardBySetAndNumber');
            $mock->shouldNotReceive('findCard');
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection/scan', [
                'image' => $base64Image,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('name', 'Opt')
            ->assertJsonPath('scryfall_id', 'f9b8a159-5e58-4432-8ecd-62f39afa96da');
    }

    public function test_it_returns_error_when_scan_produces_no_text()
    {
        $base64Image = base64_encode('fake-image-content');

        $this->mock(CloudVisionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('extractCardTexts')
                ->andReturn("");
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection/scan', [
                'image' => $base64Image,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_it_returns_error_when_scan_produces_unrecognizable_card()
    {
        $base64Image = base64_encode('fake-image-content');

        $this->mock(CloudVisionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('extractCardTexts')
                ->andReturn("\n\n\n");
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/collection/scan', [
                'image' => $base64Image,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_it_searches_the_collection()
    {
        $card1 = Card::factory()->create(['name' => 'Opt']);
        $card2 = Card::factory()->create(['name' => 'Counterspell']);

        $this->user->collection()->attach($card1->id, ['quantity' => 1, 'foil' => false]);
        $this->user->collection()->attach($card2->id, ['quantity' => 1, 'foil' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/collection/search?q=Opt');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Opt');
    }

    public function test_it_returns_empty_array_for_empty_search_query()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/collection/search?q=');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }
}
