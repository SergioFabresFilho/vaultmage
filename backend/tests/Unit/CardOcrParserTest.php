<?php

namespace Tests\Unit;

use App\Services\CardOcrParser;
use PHPUnit\Framework\TestCase;

class CardOcrParserTest extends TestCase
{
    private CardOcrParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CardOcrParser();
    }

    public function test_it_parses_standard_format_with_set_code_first()
    {
        $ocrText = "Opt\nInstant\nScry 1. Draw a card.\nM21 · 059/274";
        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Opt', $result['name']);
        $this->assertEquals('M21', $result['set_code']);
        $this->assertEquals('059', $result['collector_number']);
    }

    public function test_it_parses_standard_format_with_collector_number_first()
    {
        $ocrText = "Counterspell\nInstant\n045/261 · MH2";
        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Counterspell', $result['name']);
        $this->assertEquals('MH2', $result['set_code']);
        $this->assertEquals('045', $result['collector_number']);
    }

    public function test_it_parses_format_with_rarity_letter()
    {
        $ocrText = "Jeweled Lotus\nArtifact\n174/361 M CMR PT ...";
        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Jeweled Lotus', $result['name']);
        $this->assertEquals('CMR', $result['set_code']);
        $this->assertEquals('174', $result['collector_number']);
    }

    public function test_it_parses_format_with_newline_between_collector_and_set()
    {
        $ocrText = "Sol Ring\nArtifact\n123/274 C\nC21 PT ...";
        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Sol Ring', $result['name']);
        $this->assertEquals('C21', $result['set_code']);
        $this->assertEquals('123', $result['collector_number']);
    }

    public function test_it_returns_null_set_info_if_not_found()
    {
        $ocrText = "Black Lotus\nArtifact";
        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Black Lotus', $result['name']);
        $this->assertNull($result['set_code']);
        $this->assertNull($result['collector_number']);
    }

    public function test_it_handles_empty_text()
    {
        $result = $this->parser->parse("");

        $this->assertEquals('', $result['name']);
        $this->assertNull($result['set_code']);
        $this->assertNull($result['collector_number']);
    }

    public function test_it_does_not_confuse_power_toughness_and_copyright_for_set_info()
    {
        $ocrText = <<<TEXT
Wildwood Patrol
2
Creature - Centaur Scout
M21
Bot
Trample (This creature can deal excess combat damage to the player or planeswalker it's attacking.)
339                                                          TM21
C
EN DAN SCOTT
4/2
TM & ©2020 Wizards of the Coast
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Wildwood Patrol', $result['name']);
        $this->assertEquals('M21', $result['set_code']);
        $this->assertNull($result['collector_number']);
    }

    public function test_it_parses_standalone_collector_number_followed_by_rarity_and_set_code()
    {
        $ocrText = <<<TEXT
Dawnhart Rejuvenator
3
Creature
Human Warlock
When Dawnhart Rejuvenator enters
the battlefield, you gain 3 life.
e: Add one mana of
any color.
2/4
301
C
MID EN CABROL
TM & ©2021 Wizards of the Coast
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Dawnhart Rejuvenator', $result['name']);
        $this->assertEquals('MID', $result['set_code']);
        $this->assertEquals('301', $result['collector_number']);
    }

    public function test_it_normalizes_noisy_prefixed_set_code_after_collector_number()
    {
        $ocrText = <<<TEXT
Dawnhart Rejuvenator
3+
Creature
-
Human Warlock
When Dawnhart Rejuvenator enters
the battlefield, you gain 3 life.
e: Add one mana of any color.
2/4
301
YMID EN CABROL
TM & ©2021 Wizards of the Coast
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Dawnhart Rejuvenator', $result['name']);
        $this->assertEquals('MID', $result['set_code']);
        $this->assertEquals('301', $result['collector_number']);
    }

    public function test_it_prefers_a_real_standalone_set_code_line_over_rules_text_words()
    {
        $ocrText = <<<TEXT
Sentinel Spider
3
Creature-Spider
Vigilance (Attacking doesn't cause this
creature to tap.)
Reach (This creature can block creatures
with flying.)
M13
"Your first reaction may be to stand very still
and hope she didn't see you. Trust me, she did."
4/4
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Sentinel Spider', $result['name']);
        $this->assertEquals('M13', $result['set_code']);
        $this->assertNull($result['collector_number']);
    }

    public function test_it_parses_noisy_set_code_line_before_four_digit_collector_number()
    {
        $ocrText = <<<TEXT
Forest
Basic Land Forest
の
YLCI EN CARLOS PALMA CRUCHAGA
0401
TM & © 2023 Wizards of the Coast
readmaw
CreatureDinosaur
the
deal
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Forest', $result['name']);
        $this->assertEquals('LCI', $result['set_code']);
        $this->assertEquals('0401', $result['collector_number']);
    }

    public function test_it_skips_copyright_noise_between_collector_and_set_code()
    {
        $ocrText = <<<TEXT
Forest
89
Help
0285
& 2024 Wizards of the Coast
MKM EN JORGE JACINTO
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Forest', $result['name']);
        $this->assertEquals('MKM', $result['set_code']);
        $this->assertEquals('0285', $result['collector_number']);
    }

    public function test_it_parses_prefixed_collector_number_line_before_bottom_noise()
    {
        $ocrText = <<<TEXT
Forest
Basic Land Forest
-
TLCI EN CARLOS PALMA CRUCHAGA
L 0401
Aruímos
Front-end
160
TM & © 2023 Wizards of the Coast
BBB
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Forest', $result['name']);
        $this->assertEquals('LCI', $result['set_code']);
        $this->assertEquals('0401', $result['collector_number']);
    }

    public function test_it_normalizes_noisy_prefixed_set_code_after_inline_collector_number()
    {
        $ocrText = <<<TEXT
Snapping Gnarlid
1
Creature
-
Beast
Landfall Whenever a land enters
the battlefield under your control,
Snapping Gnarlid gets +1/+1 until end
of turn.
2/2
190/274 C
YBFZ EN KEY WALKER
& © 2015 Wizards of the Coast
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Snapping Gnarlid', $result['name']);
        $this->assertEquals('BFZ', $result['set_code']);
        $this->assertEquals('190', $result['collector_number']);
    }

    public function test_it_extracts_basic_land_name_when_first_line_is_noise()
    {
        $ocrText = <<<TEXT
T
Neve a
Forest
Basic Land Forest
-
L 0276
TWOE EN ADAM PAQUETTE
TM & © 2023 Wizards of the Coast
TEXT;

        $result = $this->parser->parse($ocrText);

        $this->assertEquals('Forest', $result['name']);
        $this->assertEquals('WOE', $result['set_code']);
        $this->assertEquals('0276', $result['collector_number']);
    }
}
