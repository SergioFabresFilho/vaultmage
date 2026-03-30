<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\GoogleOcrProcessor;
use Google\Cloud\Vision\V1\AnnotateImageResponse;
use Google\Cloud\Vision\V1\BatchAnnotateImagesResponse;
use Google\Cloud\Vision\V1\EntityAnnotation;
use Google\Rpc\Status;
use Tests\TestCase;

class GoogleOcrProcessorTest extends TestCase
{
    private GoogleOcrProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new GoogleOcrProcessor();
    }

    public function test_it_builds_batch_request()
    {
        $imageDatas = ['fake-data-1', 'fake-data-2'];
        
        $request = $this->processor->buildBatchRequest($imageDatas);
        
        $this->assertCount(2, $request->getRequests());
        $this->assertEquals('fake-data-1', $request->getRequests()[0]->getImage()->getContent());
    }

    public function test_it_parses_batch_response()
    {
        $annotation = new EntityAnnotation();
        $annotation->setDescription('Hello World');

        $response = new AnnotateImageResponse();
        $response->setTextAnnotations([$annotation]);

        $batchResponse = new BatchAnnotateImagesResponse();
        $batchResponse->setResponses([$response]);

        $results = $this->processor->parseBatchResponse($batchResponse);

        $this->assertCount(1, $results);
        $this->assertEquals('Hello World', $results[0]);
    }

    public function test_it_handles_response_errors()
    {
        $error = new Status();
        $error->setMessage('API Error');

        $response = new AnnotateImageResponse();
        $response->setError($error);

        $batchResponse = new BatchAnnotateImagesResponse();
        $batchResponse->setResponses([$response]);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with('Cloud Vision batch item error', \Mockery::on(function($args) {
                return $args['message'] === 'API Error';
            }));

        $results = $this->processor->parseBatchResponse($batchResponse);

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]);
    }

    public function test_it_handles_empty_annotations()
    {
        $response = new AnnotateImageResponse();
        // No text annotations

        $batchResponse = new BatchAnnotateImagesResponse();
        $batchResponse->setResponses([$response]);

        $results = $this->processor->parseBatchResponse($batchResponse);

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]);
    }
}
