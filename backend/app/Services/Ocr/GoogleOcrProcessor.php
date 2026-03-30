<?php

namespace App\Services\Ocr;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesResponse;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;
use Illuminate\Support\Facades\Log;

class GoogleOcrProcessor
{
    /**
     * @param array<string> $imageDatas Raw image bytes
     */
    public function buildBatchRequest(array $imageDatas): BatchAnnotateImagesRequest
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

        return $batchRequest;
    }

    /**
     * @return array<string> OCR text results
     */
    public function parseBatchResponse(BatchAnnotateImagesResponse $batchResponse): array
    {
        $responses = $batchResponse->getResponses();
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
