<?php

namespace App\Services;

use App\Contracts\OcrClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        return $this->extractCardTexts([$base64Image]);
    }

    /**
     * Extract text from one or more targeted MTG card image regions.
     *
     * @param  array<string>  $base64Images
     */
    public function extractCardTexts(array $base64Images): string
    {
        $imageDatas = array_map(fn (string $image) => $this->decodeImage($image), $base64Images);
        $this->storeDebugImages($imageDatas);

        $cacheKey = 'ocr:card:' . md5(implode('|', array_map('md5', $imageDatas)));

        return Cache::rememberForever($cacheKey, function () use ($imageDatas, $cacheKey) {
            $texts = $this->client->textDetection($imageDatas);

            $fullText = collect($texts)
                ->map(fn (string $text) => trim($text))
                ->filter()
                ->implode("\n");

            Log::debug('CloudVision card OCR raw', [
                'full_text' => $fullText,
                'regions' => count($imageDatas),
                'cache_key' => $cacheKey,
            ]);

            return $fullText;
        });
    }

    /**
     * @param  array<string>  $imageDatas
     * @return array<string>
     */
    private function storeDebugImages(array $imageDatas): array
    {
        if (! app()->environment(['local', 'testing'])) {
            return [];
        }

        $scanId = now()->format('Ymd_His') . '_' . Str::lower(Str::random(8));
        $paths = [];

        foreach ($imageDatas as $index => $imageData) {
            $path = "debug-ocr/{$scanId}_region_" . ($index + 1) . '.jpg';
            Storage::disk('local')->put($path, $imageData);
            $paths[] = Storage::disk('local')->path($path);
        }

        Log::debug('CloudVision card OCR images saved', [
            'regions' => count($paths),
            'paths' => $paths,
        ]);

        return $paths;
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
