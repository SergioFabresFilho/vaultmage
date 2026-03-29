<?php

namespace App\Services;

use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Support\Facades\Cache;
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
            throw new RuntimeException('Invalid base64 image data.');
        }

        $cacheKey = 'ocr:' . md5($imageData);

        return Cache::rememberForever($cacheKey, function () use ($imageData) {
            $image = new Image();
            $image->setContent($imageData);

            $response = $this->client->textDetection($image);

            if ($response->hasError()) {
                throw new RuntimeException('Cloud Vision error: ' . $response->getError()->getMessage());
            }

            $annotations = $response->getTextAnnotations();

            if ($annotations->count() === 0) {
                return '';
            }

            // First annotation contains the full concatenated text
            return $annotations->offsetGet(0)->getDescription();
        });
    }
}
