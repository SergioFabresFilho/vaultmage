<?php

namespace App\Contracts;

interface OcrClient
{
    /**
     * @param array<string> $imageDatas Raw image bytes
     * @return array<string> OCR text results
     */
    public function textDetection(array $imageDatas): array;
}
