<?php

namespace Tests\Feature;

use App\Contracts\OcrClient;
use App\Services\CloudVisionService;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class CloudVisionServiceTest extends TestCase
{
    public function test_it_extracts_text_from_image()
    {
        $base64Image = base64_encode('fake-image-data');
        $expectedText = "Opt\nInstant\nM21 · 059/274";

        $this->mock(OcrClient::class, function (MockInterface $mock) use ($expectedText) {
            $mock->shouldReceive('textDetection')
                ->once()
                ->with([base64_decode(base64_encode('fake-image-data'))])
                ->andReturn([$expectedText]);
        });

        /** @var CloudVisionService $service */
        $service = app(CloudVisionService::class);
        $result = $service->extractText($base64Image);

        $this->assertEquals($expectedText, $result);
    }

    public function test_it_caches_the_result()
    {
        $base64Image = base64_encode('fake-image-data-2');
        $expectedText = "Cached Text";

        $this->mock(OcrClient::class, function (MockInterface $mock) use ($expectedText) {
            $mock->shouldReceive('textDetection')
                ->once() // Ensure it's only called once
                ->andReturn([$expectedText]);
        });

        /** @var CloudVisionService $service */
        $service = app(CloudVisionService::class);
        
        // First call
        $service->extractText($base64Image);
        
        // Second call should hit cache
        $result = $service->extractText($base64Image);

        $this->assertEquals($expectedText, $result);
    }

    public function test_it_extracts_card_text()
    {
        $base64Image = base64_encode('fake-card-data');
        $expectedText = "Magic Card Text";

        $this->mock(OcrClient::class, function (MockInterface $mock) use ($expectedText) {
            $mock->shouldReceive('textDetection')
                ->once()
                ->andReturn([$expectedText]);
        });

        /** @var CloudVisionService $service */
        $service = app(CloudVisionService::class);
        $result = $service->extractCardText($base64Image);

        $this->assertEquals($expectedText, $result);
    }

    public function test_it_extracts_card_text_from_multiple_regions()
    {
        $top = base64_encode('fake-card-top');
        $bottom = base64_encode('fake-card-bottom');

        $this->mock(OcrClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('textDetection')
                ->once()
                ->with([base64_decode('ZmFrZS1jYXJkLXRvcA=='), base64_decode('ZmFrZS1jYXJkLWJvdHRvbQ==')])
                ->andReturn(['Overcome', "M19\n186/280 C"]);
        });

        /** @var CloudVisionService $service */
        $service = app(CloudVisionService::class);
        $result = $service->extractCardTexts([$top, $bottom]);

        $this->assertEquals("Overcome\nM19\n186/280 C", $result);
    }

    public function test_it_throws_exception_on_invalid_base64()
    {
        /** @var CloudVisionService $service */
        $service = app(CloudVisionService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64 image data.');

        $service->extractText('!!!not-base64!!!');
    }
}
