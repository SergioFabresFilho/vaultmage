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
}
