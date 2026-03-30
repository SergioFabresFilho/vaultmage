<?php

namespace App\Services;

class CardOcrParser
{
    /**
     * Parse OCR text from a Magic: The Gathering card.
     *
     * Returns the best guesses for card name, set code, and collector number.
     * Card name is typically the first non-empty line.
     * Set code and collector number are extracted from the bottom-of-card line,
     * e.g. "M21 · 123/274" or "123/274 · M21".
     *
     * @return array{name: string, set_code: string|null, collector_number: string|null}
     */
    public function parse(string $ocrText): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $ocrText)),
            fn (string $line) => $line !== ''
        ));

        $name = $lines[0] ?? '';

        ['set_code' => $setCode, 'collector_number' => $collectorNumber] = $this->extractSetInfo($ocrText);

        return [
            'name'             => $name,
            'set_code'         => $setCode,
            'collector_number' => $collectorNumber,
        ];
    }

    /**
     * Look for patterns like "M21", "MH3", "DMU", "2X2", "40K" near collector number
     * patterns such as "123/274".
     *
     * MTG set codes are 2–4 uppercase alphanumeric characters.
     * Collector numbers are the digits before the "/" (e.g. "123" from "123/274").
     *
     * Handled formats:
     *   "M21 · 123/274"          — set code before collector, with separator
     *   "123/274 · M21"          — collector before set code, with separator
     *   "174/361 C CMR PT ..."   — no separator, rarity letter between collector and set code
     *   "174/361 C\nCMR PT ..."  — set code on the line immediately after the collector line
     *
     * @return array{set_code: string|null, collector_number: string|null}
     */
    private function extractSetInfo(string $text): array
    {
        // "M21 · 123/274"
        if (preg_match('/\b([A-Z0-9]{2,4})\s*[·•\-]\s*(\d+)\/\d+/iu', $text, $m)) {
            return ['set_code' => strtoupper($m[1]), 'collector_number' => $m[2]];
        }

        // "123/274 · M21"
        if (preg_match('/(\d+)\/\d+\s*[·•\-]\s*([A-Z0-9]{2,4})\b/iu', $text, $m)) {
            return ['set_code' => strtoupper($m[2]), 'collector_number' => $m[1]];
        }

        // "174/361 C CMR PT ..." — rarity letter separates collector from set code (same line)
        if (preg_match('/(\d+)\/\d+\s+[CURMTS]\s+([A-Z][A-Z0-9]{1,3})\b/iu', $text, $m)) {
            return ['set_code' => strtoupper($m[2]), 'collector_number' => $m[1]];
        }

        // "174/361 C\nCMR PT ..." — set code is first token on the line after the collector line
        if (preg_match('/(\d+)\/\d+[^\n]*\n\s*([A-Z][A-Z0-9]{1,3})\b/u', $text, $m)) {
            return ['set_code' => strtoupper($m[2]), 'collector_number' => $m[1]];
        }

        return ['set_code' => null, 'collector_number' => null];
    }
}
