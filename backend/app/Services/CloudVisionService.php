<?php

namespace App\Services;

use App\Contracts\OcrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudVisionService
{
    private OcrClient $client;

    public function __construct(OcrClient $client)
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
        $imageData = $this->decodeImage($base64Image);

        $cacheKey = 'ocr:' . md5($imageData);

        return Cache::rememberForever($cacheKey, function () use ($imageData, $cacheKey) {
            $texts = $this->client->textDetection([$imageData]);

            if (empty($texts)) {
                Log::warning('Cloud Vision returned no responses', ['cache_key' => $cacheKey]);
                return '';
            }

            return $texts[0];
        });
    }

    /**
     * Extract text from an MTG card image.
     *
     * @param  string  $base64Image  Raw base64 string (no data URI prefix)
     * @return string  OCR text from the full image
     */
    public function extractCardText(string $base64Image): string
    {
        $imageData = $this->decodeImage($base64Image);

        $cacheKey = 'ocr:card:' . md5($imageData);

        return Cache::rememberForever($cacheKey, function () use ($imageData, $cacheKey) {
            $texts = $this->client->textDetection([$imageData]);

            $fullText = $texts[0] ?? '';

            Log::debug('CloudVision card OCR raw', [
                'full_text' => $fullText,
                'cache_key' => $cacheKey,
            ]);

            return $fullText;
        });
    }

    private function decodeImage(string $base64Image): string
    {
        $imageData = base64_decode($base64Image, strict: true);

        if ($imageData === false) {
            Log::error('CloudVisionService received invalid base64 image data');
            throw new RuntimeException('Invalid base64 image data.');
        }

        return $imageData;
    }
}
