<?php

namespace App\Services;

use App\Contracts\OcrClient;
use App\Contracts\VisionClientInterface;
use App\Services\Ocr\GoogleOcrProcessor;

class GoogleOcrClient implements OcrClient
{
    private VisionClientInterface $client;
    private GoogleOcrProcessor $processor;

    public function __construct(VisionClientInterface $client, GoogleOcrProcessor $processor)
    {
        $this->client = $client;
        $this->processor = $processor;
    }

    public function textDetection(array $imageDatas): array
    {
        $batchRequest = $this->processor->buildBatchRequest($imageDatas);

        $batchResponse = $this->client->batchAnnotateImages($batchRequest);

        return $this->processor->parseBatchResponse($batchResponse);
    }
}
