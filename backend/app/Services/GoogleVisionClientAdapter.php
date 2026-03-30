<?php

namespace App\Services;

use App\Contracts\VisionClientInterface;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesResponse;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;

class GoogleVisionClientAdapter implements VisionClientInterface
{
    public function __construct(private ImageAnnotatorClient $client) {}

    public function batchAnnotateImages(BatchAnnotateImagesRequest $request): BatchAnnotateImagesResponse
    {
        return $this->client->batchAnnotateImages($request);
    }
}
