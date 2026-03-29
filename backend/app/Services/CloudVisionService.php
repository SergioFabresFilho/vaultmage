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
            $texts = $this->batchAnnotate([$imageData]);

            if (empty($texts)) {
                Log::warning('Cloud Vision returned no responses', ['cache_key' => $cacheKey]);
                return '';
            }

            return $texts[0];
        });
    }

    /**
     * Extract text from an MTG card image using a two-pass batch request:
     *   1. Full image  — ensures the card name (first line) is reliably detected
     *   2. Bottom 25%  — focused crop of the collector info strip for set code / collector number
     *
     * The two results are concatenated so the parser can find both pieces in one pass.
     *
     * @param  string  $base64Image  Raw base64 string (no data URI prefix)
     * @return string  Combined OCR text from full image + bottom strip
     */
    public function extractCardText(string $base64Image): string
    {
        $imageData = base64_decode($base64Image, strict: true);

        if ($imageData === false) {
            Log::error('CloudVisionService received invalid base64 image data');
            throw new RuntimeException('Invalid base64 image data.');
        }

        $cacheKey = 'ocr:card:' . md5($imageData);

        return Cache::rememberForever($cacheKey, function () use ($imageData, $cacheKey) {
            $texts = $this->batchAnnotate([$imageData]);

            $fullText = $texts[0] ?? '';

            Log::debug('CloudVision card OCR raw', [
                'full_text' => $fullText,
                'cache_key' => $cacheKey,
            ]);

            return $fullText;
        });
    }

    /**
     * Send multiple raw image byte strings to Vision API in one batch request.
     * Returns an array of OCR text strings in the same order as the input.
     *
     * @param  string[]  $imageDatas  Array of raw image bytes
     * @return string[]
     */
    private function batchAnnotate(array $imageDatas): array
    {
        $requests = [];

        foreach ($imageDatas as $data) {
            $image = new Image();
            $image->setContent($data);

            $feature = new Feature();
            $feature->setType(Feature\Type::TEXT_DETECTION);

            $req = new AnnotateImageRequest();
            $req->setImage($image);
            $req->setFeatures([$feature]);

            $requests[] = $req;
        }

        $batchRequest = new BatchAnnotateImagesRequest();
        $batchRequest->setRequests($requests);

        $batchResponse = $this->client->batchAnnotateImages($batchRequest);
        $responses     = $batchResponse->getResponses();

        $texts = [];

        foreach ($responses as $i => $response) {
            if ($response->hasError()) {
                $msg = $response->getError()->getMessage();
                Log::warning('Cloud Vision batch item error', ['index' => $i, 'message' => $msg]);
                $texts[] = '';
                continue;
            }

            $annotations = $response->getTextAnnotations();
            $texts[] = $annotations->count() > 0
                ? $annotations->offsetGet(0)->getDescription()
                : '';
        }

        return $texts;
    }
}
