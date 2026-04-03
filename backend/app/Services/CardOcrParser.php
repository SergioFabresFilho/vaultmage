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

        $name = $this->extractName($lines);

        ['set_code' => $setCode, 'collector_number' => $collectorNumber] = $this->extractSetInfo($ocrText);

        return [
            'name'             => $name,
            'set_code'         => $setCode,
            'collector_number' => $collectorNumber,
        ];
    }

    private function extractName(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/\bBasic Land\s+(Plains|Island|Swamp|Mountain|Forest|Wastes)\b/i', $line, $m)) {
                return $m[1];
            }
        }

        foreach (array_slice($lines, 0, 6) as $line) {
            $candidate = trim($line);

            if ($candidate === '') {
                continue;
            }

            if (mb_strlen($candidate) < 2) {
                continue;
            }

            if (preg_match('/\d/', $candidate)) {
                continue;
            }

            return $candidate;
        }

        return $lines[0] ?? '';
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
     *   "M21"                    — standalone set code line when collector OCR is unreliable
     *
     * @return array{set_code: string|null, collector_number: string|null}
     */
    private function extractSetInfo(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        // "M21 · 123/274"
        if (preg_match('/\b([A-Z0-9]{2,4})\s*[·•\-]\s*(\d+)\/\d+/iu', $text, $m)) {
            $setCode = $this->normalizeSetCodeCandidate($m[1]);
            if ($setCode !== null) {
                return ['set_code' => $setCode, 'collector_number' => $m[2]];
            }
        }

        // "123/274 · M21"
        if (preg_match('/(\d+)\/\d+\s*[·•\-]\s*([A-Z0-9]{2,4})\b/iu', $text, $m)) {
            $setCode = $this->normalizeSetCodeCandidate($m[2]);
            if ($setCode !== null) {
                return ['set_code' => $setCode, 'collector_number' => $m[1]];
            }
        }

        // "174/361 C CMR PT ..." — rarity letter separates collector from set code (same line)
        if (preg_match('/(\d{1,3})\/(\d{2,4})[ \t]+[CURMLTSB][ \t]+([A-Z][A-Z0-9]{1,3})\b/iu', $text, $m)) {
            $setCode = $this->normalizeSetCodeCandidate($m[3]);
            if ($setCode !== null) {
                return ['set_code' => $setCode, 'collector_number' => $m[1]];
            }
        }

        // "190/274 C\nYBFZ EN ..." — set code line after collector line, with language marker
        if (preg_match('/(\d{1,3})\/(\d{2,4})[^\n]*\n\s*([A-Z0-9]{2,6}\s+(?:EN|DE|ES|FR|IT|JP|PT)\b[^\n]*)/iu', $text, $m)) {
            $setCode = $this->extractSetCodeFromLine($m[3]);
            if ($setCode !== null) {
                return ['set_code' => $setCode, 'collector_number' => $m[1]];
            }
        }

        // "174/361 C\nCMR PT ..." — set code is first token on the line after the collector line
        if (preg_match('/(\d{1,3})\/(\d{2,4})[^\n]*\n\s*([A-Z][A-Z0-9]{1,3})\b/u', $text, $m)) {
            $setCode = $this->normalizeSetCodeCandidate($m[3]);
            if ($setCode !== null) {
                return ['set_code' => $setCode, 'collector_number' => $m[1]];
            }
        }

        $lineCount = count($lines);
        $bestCandidate = null;
        $bestScore = -1;

        for ($i = $lineCount - 1; $i >= 0; $i--) {
            $collectorLine = trim($lines[$i]);
            $collectorNumber = $this->extractCollectorNumberFromLine($collectorLine);

            if ($collectorNumber !== null) {
                $previous = $this->nearestMeaningfulLine($lines, $i, -1);
                $next = $this->nearestMeaningfulLine($lines, $i, 1);
                $afterNext = $this->nearestMeaningfulLine($lines, $i, 1, 2);

                // Prefer a strong metadata line immediately above the collector, e.g. "TLCI EN ..."
                if ($this->lineHasLanguageMarker($previous) && ($setCode = $this->extractSetCodeFromLine($previous)) !== null) {
                    $this->recordCollectorCandidate($bestCandidate, $bestScore, $setCode, $collectorNumber, 5);
                }

                // "301\nC\nMID EN ..." — standalone collector number, rarity on next line, set code after that
                if (preg_match('/^[CURMLTSB]$/', $next) && ($setCode = $this->extractSetCodeFromLine($afterNext)) !== null) {
                    $score = $this->lineHasLanguageMarker($afterNext) ? 4 : 3;
                    $this->recordCollectorCandidate($bestCandidate, $bestScore, $setCode, $collectorNumber, $score);
                }

                // "301\nMID EN ..." — standalone collector number followed directly by set code line
                if (($setCode = $this->extractSetCodeFromLine($next)) !== null) {
                    $score = $this->lineHasLanguageMarker($next) ? 4 : 2;
                    $this->recordCollectorCandidate($bestCandidate, $bestScore, $setCode, $collectorNumber, $score);
                }

                // "YLCI EN ...\n0401" — standalone set code line before collector number
                if (($setCode = $this->extractSetCodeFromLine($previous)) !== null) {
                    $score = $this->lineHasLanguageMarker($previous) ? 5 : 2;
                    $this->recordCollectorCandidate($bestCandidate, $bestScore, $setCode, $collectorNumber, $score);
                }
            }
        }

        if ($bestCandidate !== null) {
            return $bestCandidate;
        }

        $fallbackLines = array_slice($lines, max(0, $lineCount - 8));
        foreach ($fallbackLines as $line) {
            $candidate = strtoupper(trim($line));

            if (($setCode = $this->extractStandaloneSetCode($candidate)) !== null) {
                return ['set_code' => $setCode, 'collector_number' => null];
            }
        }

        return ['set_code' => null, 'collector_number' => null];
    }

    private function looksLikeStandaloneSetCode(string $candidate): bool
    {
        if (! preg_match('/^[A-Z0-9]{2,4}$/', $candidate)) {
            return false;
        }

        if (preg_match('/^\d+$/', $candidate)) {
            return false;
        }

        // Common OCR/footer noise and language tokens that appear near collector info.
        if (in_array($candidate, ['EN', 'DE', 'ES', 'FR', 'IT', 'JP', 'PT', 'TM'], true)) {
            return false;
        }

        return true;
    }

    private function extractSetCodeFromLine(string $line): ?string
    {
        if (! preg_match('/^([A-Z0-9]{2,6})(?:\s+([A-Z]{2}))?\b/', $line, $m)) {
            return null;
        }

        $candidate = strtoupper($m[1]);
        $nextToken = strtoupper($m[2] ?? '');

        if (in_array($nextToken, ['EN', 'DE', 'ES', 'FR', 'IT', 'JP', 'PT'], true) && strlen($candidate) === 4) {
            $suffix = substr($candidate, 1);

            if ($this->looksLikeStandaloneSetCode($suffix)) {
                return $suffix;
            }
        }

        return $this->normalizeSetCodeCandidate($candidate);
    }

    private function normalizeSetCodeCandidate(string $candidate): ?string
    {
        $candidate = strtoupper(trim($candidate));

        if ($this->looksLikeStandaloneSetCode($candidate)) {
            return $candidate;
        }

        // OCR can prepend a stray capital to the real code, e.g. "YMID" for "MID".
        if (strlen($candidate) === 4) {
            $suffix = substr($candidate, 1);

            if ($this->looksLikeStandaloneSetCode($suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    private function extractStandaloneSetCode(string $line): ?string
    {
        if (! preg_match('/^[A-Z0-9]{2,4}$/', $line)) {
            return null;
        }

        return $this->normalizeSetCodeCandidate($line);
    }

    private function nearestMeaningfulLine(array $lines, int $startIndex, int $direction, int $steps = 1): string
    {
        $index = $startIndex;
        $found = 0;

        while (true) {
            $index += $direction;

            if (! array_key_exists($index, $lines)) {
                return '';
            }

            $candidate = strtoupper(trim($lines[$index]));
            if ($candidate === '' || $this->isIgnorableMetadataLine($candidate)) {
                continue;
            }

            $found++;
            if ($found === $steps) {
                return $candidate;
            }
        }
    }

    private function isIgnorableMetadataLine(string $line): bool
    {
        return str_contains($line, 'WIZARDS OF THE COAST')
            || str_starts_with($line, 'TM &')
            || str_starts_with($line, '& ')
            || preg_match('/^\d{4}\s+WIZARDS OF THE COAST$/', $line) === 1;
    }

    private function extractCollectorNumberFromLine(string $line): ?string
    {
        $candidate = strtoupper(trim($line));

        if (preg_match('/^\d{1,4}$/', $candidate)) {
            return $candidate;
        }

        // OCR sometimes prefixes the collector line with a stray rarity/set glyph, e.g. "L 0401".
        if (preg_match('/^[A-Z]\s+(\d{3,4})$/', $candidate, $m)) {
            return $m[1];
        }

        return null;
    }

    private function lineHasLanguageMarker(string $line): bool
    {
        return preg_match('/\b(EN|DE|ES|FR|IT|JP|PT)\b/', $line) === 1;
    }

    private function recordCollectorCandidate(?array &$bestCandidate, int &$bestScore, string $setCode, string $collectorNumber, int $score): void
    {
        if ($score <= $bestScore) {
            return;
        }

        $bestScore = $score;
        $bestCandidate = [
            'set_code' => $setCode,
            'collector_number' => $collectorNumber,
        ];
    }
}
