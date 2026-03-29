<?php

namespace App\Services;

class CardOcrParser
{
    /**
     * Parse OCR text from a Magic: The Gathering card.
     *
     * Returns the best guesses for card name and set code.
     * Card name is typically the first non-empty line.
     * Set code is a 3–4 character alphanumeric token found near collector number patterns.
     *
     * @return array{name: string, set_code: string|null}
     */
    public function parse(string $ocrText): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $ocrText)),
            fn (string $line) => $line !== ''
        ));

        $name = $lines[0] ?? '';

        $setCode = $this->extractSetCode($ocrText);

        return [
            'name'     => $name,
            'set_code' => $setCode,
        ];
    }

    /**
     * Look for patterns like "M21", "MH3", "DMU", "2X2", "40K" near collector number
     * patterns such as "123/274" or "★" or "•".
     *
     * MTG set codes are 3–4 uppercase alphanumeric characters.
     */
    private function extractSetCode(string $text): ?string
    {
        // Match set code adjacent to collector number: e.g. "M21 · 123/274" or "123/274 · M21"
        if (preg_match('/\b([A-Z0-9]{2,4})\s*[·•\-]\s*\d+\/\d+/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\d+\/\d+\s*[·•\-]\s*([A-Z0-9]{2,4})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
