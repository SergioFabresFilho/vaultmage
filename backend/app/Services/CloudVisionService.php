<?php

namespace App\Services;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudVisionService
{
    private ImageAnnotatorClient $client;

    public function __construct(ImageAnnotatorClient $client)
    {
        $this->client = $client;
    }

    /**
     * Extract all text from a base64-encoded image.
     *
     * @param  string  $base64Image  Raw base64 string (no data URI prefix)
     * @return string  Full extracted text
     */
    public function extractText(string $base64Image): string
    {
        $imageData = base64_decode($base64Image, strict: true);

        if ($imageData === false) {
            Log::error('CloudVisionService received invalid base64 image data');
            throw new RuntimeException('Invalid base64 image data.');
        }

        $cacheKey = 'ocr:' . md5($imageData);

        return Cache::rememberForever($cacheKey, function () use ($imageData, $cacheKey) {
            $image = new Image();
            $image->setContent($imageData);

            $feature = new Feature();
            $feature->setType(Feature\Type::TEXT_DETECTION);

            $annotateRequest = new AnnotateImageRequest();
            $annotateRequest->setImage($image);
            $annotateRequest->setFeatures([$feature]);

            $batchRequest = new BatchAnnotateImagesRequest();
            $batchRequest->setRequests([$annotateRequest]);

            $batchResponse = $this->client->batchAnnotateImages($batchRequest);
            $responses = $batchResponse->getResponses();

            if ($responses->count() === 0) {
                Log::warning('Cloud Vision returned no responses', ['cache_key' => $cacheKey]);
                return '';
            }

            $response = $responses->offsetGet(0);

            if ($response->hasError()) {
                $errorMessage = $response->getError()->getMessage();
                Log::error('Cloud Vision API returned an error', ['message' => $errorMessage]);
                throw new RuntimeException('Cloud Vision error: ' . $errorMessage);
            }

            $annotations = $response->getTextAnnotations();

            if ($annotations->count() === 0) {
                Log::warning('Cloud Vision returned no text annotations', ['cache_key' => $cacheKey]);
                return '';
            }

            // First annotation contains the full concatenated text
            return $annotations->offsetGet(0)->getDescription();
        });
    }
}
