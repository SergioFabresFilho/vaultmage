<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchCardsToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'title' => null,
        ]);
    }

    public function test_search_cards_prefers_exact_name_matches(): void
    {
        Card::factory()->create([
            'name' => 'Counterspell',
            'type_line' => 'Instant',
            'oracle_text' => 'Counter target spell.',
            'color_identity' => ['U'],
            'legalities' => ['modern' => 'legal'],
            'price_usd' => 1.00,
        ]);

        Card::factory()->create([
            'name' => 'Spell Pierce',
            'type_line' => 'Instant',
            'oracle_text' => 'Counter target noncreature spell unless its controller pays {2}.',
            'color_identity' => ['U'],
            'legalities' => ['modern' => 'legal'],
            'price_usd' => 0.50,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'Counterspell',
                    'colors' => ['U'],
                    'format' => 'modern',
                ], 'call_1'), 200)
                ->push($this->textResponse('Done.'), 200),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Search for Counterspell.',
            ])
            ->assertOk();

        $toolMessage = $this->conversation->fresh()->messages()->where('role', 'tool')->first();
        $payload = json_decode($toolMessage->content, true);

        $this->assertSame('Counterspell', $payload['cards'][0]['name']);
    }

    public function test_search_cards_uses_role_aware_ranking_for_ramp_queries(): void
    {
        Card::factory()->create([
            'name' => 'Mind Stone',
            'type_line' => 'Artifact',
            'oracle_text' => '{T}: Add {C}.',
            'color_identity' => [],
            'legalities' => ['commander' => 'legal'],
            'price_usd' => 0.25,
        ]);

        Card::factory()->create([
            'name' => 'Goblin Motivator',
            'type_line' => 'Creature — Goblin',
            'oracle_text' => '{T}: Target creature gains haste until end of turn.',
            'color_identity' => ['R'],
            'legalities' => ['commander' => 'legal'],
            'price_usd' => 0.10,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'ramp',
                    'format' => 'commander',
                ], 'call_1'), 200)
                ->push($this->textResponse('Done.'), 200),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Find ramp cards.',
            ])
            ->assertOk();

        $toolMessage = $this->conversation->fresh()->messages()->where('role', 'tool')->first();
        $payload = json_decode($toolMessage->content, true);

        $this->assertNotEmpty($payload['cards']);
        $this->assertSame('Mind Stone', $payload['cards'][0]['name']);
    }

    public function test_search_cards_enforces_build_brief_colors_even_if_model_omits_them(): void
    {
        Card::factory()->create([
            'name' => 'Feed the Swarm',
            'type_line' => 'Sorcery',
            'oracle_text' => 'Destroy target creature or enchantment an opponent controls.',
            'color_identity' => ['B'],
            'legalities' => ['commander' => 'legal'],
            'price_usd' => 0.50,
        ]);

        Card::factory()->create([
            'name' => 'Broken Wings',
            'type_line' => 'Instant',
            'oracle_text' => 'Destroy target artifact, enchantment, or creature with flying.',
            'color_identity' => ['G'],
            'legalities' => ['commander' => 'legal'],
            'price_usd' => 0.10,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('search_cards', [
                    'query' => 'removal',
                    'format' => 'commander',
                ], 'call_1'), 200)
                ->push($this->textResponse('Done.'), 200),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a brand-new deck. Format: Commander. Commander: Syr Konrad, the Grim. Search colors: [B]. Playstyle: Aristocrats. Budget: under $100.',
            ])
            ->assertOk();

        $toolMessage = $this->conversation->fresh()->messages()->where('role', 'tool')->first();
        $payload = json_decode($toolMessage->content, true);

        $this->assertNotEmpty($payload['cards']);
        $this->assertSame(['B'], $payload['cards'][0]['color_identity']);
        $this->assertSame(['Feed the Swarm'], array_column($payload['cards'], 'name'));
    }

    public function test_search_scryfall_enforces_build_brief_colors_and_filters_results(): void
    {
        $capturedQuery = null;

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->toolCallResponse('search_scryfall', [
                    'query' => 'commander draw',
                ], 'call_1'), 200)
                ->push($this->textResponse('Done.'), 200),
            'https://api.scryfall.com/*' => function ($request) use (&$capturedQuery) {
                $capturedQuery = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

                return Http::response([
                    'data' => [
                        [
                            'id' => 'black-card',
                            'name' => 'Sign in Blood',
                            'set' => 'm11',
                            'set_name' => 'Magic 2011',
                            'collector_number' => '112',
                            'rarity' => 'common',
                            'mana_cost' => '{B}{B}',
                            'oracle_text' => 'Target player draws two cards and loses 2 life.',
                            'cmc' => 2,
                            'color_identity' => ['B'],
                            'legalities' => ['commander' => 'legal'],
                            'type_line' => 'Sorcery',
                            'prices' => ['usd' => '0.25'],
                        ],
                        [
                            'id' => 'green-card',
                            'name' => 'Harmonize',
                            'set' => 'cmm',
                            'set_name' => 'Commander Masters',
                            'collector_number' => '300',
                            'rarity' => 'uncommon',
                            'mana_cost' => '{2}{G}{G}',
                            'oracle_text' => 'Draw three cards.',
                            'cmc' => 4,
                            'color_identity' => ['G'],
                            'legalities' => ['commander' => 'legal'],
                            'type_line' => 'Sorcery',
                            'prices' => ['usd' => '0.35'],
                        ],
                    ],
                ], 200);
            },
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/chat/conversations/{$this->conversation->id}/messages", [
                'message' => 'Build me a brand-new deck. Format: Commander. Commander: Syr Konrad, the Grim. Search colors: [B]. Playstyle: Midrange. Budget: under $100.',
            ])
            ->assertOk();

        $toolMessage = $this->conversation->fresh()->messages()->where('role', 'tool')->first();
        $payload = json_decode($toolMessage->content, true);

        $this->assertNotEmpty($payload['cards']);
        $this->assertSame(['Sign in Blood'], array_column($payload['cards'], 'name'));
        $this->assertIsString($capturedQuery);
        $this->assertStringContainsString('q=commander draw id<=b', $capturedQuery);
    }

    private function toolCallResponse(string $tool, array $args, string $callId): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => [
                            'name' => $tool,
                            'arguments' => json_encode($args),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function textResponse(string $text): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                    'tool_calls' => [],
                ],
            ]],
        ];
    }
}
