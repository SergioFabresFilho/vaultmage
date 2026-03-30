<?php

namespace Tests\Unit;

use App\Contracts\VisionClientInterface;
use App\Services\GoogleOcrClient;
use App\Services\Ocr\GoogleOcrProcessor;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesResponse;
use Mockery;
use Tests\TestCase;

class GoogleOcrClientTest extends TestCase
{
    public function test_text_detection_delegates_to_processor_and_client()
    {
        $imageDatas = ['image-bytes-1', 'image-bytes-2'];
        $batchRequest = new BatchAnnotateImagesRequest();
        $batchResponse = new BatchAnnotateImagesResponse();
        $expected = ['text from image 1', 'text from image 2'];

        $processor = Mockery::mock(GoogleOcrProcessor::class);
        $processor->shouldReceive('buildBatchRequest')
            ->once()
            ->with($imageDatas)
            ->andReturn($batchRequest);

        $processor->shouldReceive('parseBatchResponse')
            ->once()
            ->with($batchResponse)
            ->andReturn($expected);

        $client = Mockery::mock(VisionClientInterface::class);
        $client->shouldReceive('batchAnnotateImages')
            ->once()
            ->with($batchRequest)
            ->andReturn($batchResponse);

        $ocrClient = new GoogleOcrClient($client, $processor);
        $result = $ocrClient->textDetection($imageDatas);

        $this->assertSame($expected, $result);
    }

    public function test_text_detection_returns_empty_array_for_empty_input()
    {
        $batchRequest = new BatchAnnotateImagesRequest();
        $batchResponse = new BatchAnnotateImagesResponse();

        $processor = Mockery::mock(GoogleOcrProcessor::class);
        $processor->shouldReceive('buildBatchRequest')->with([])->andReturn($batchRequest);
        $processor->shouldReceive('parseBatchResponse')->with($batchResponse)->andReturn([]);

        $client = Mockery::mock(VisionClientInterface::class);
        $client->shouldReceive('batchAnnotateImages')->andReturn($batchResponse);

        $ocrClient = new GoogleOcrClient($client, $processor);
        $result = $ocrClient->textDetection([]);

        $this->assertSame([], $result);
    }
}
