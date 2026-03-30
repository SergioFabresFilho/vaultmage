<?php

namespace App\Contracts;

use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesResponse;

interface VisionClientInterface
{
    public function batchAnnotateImages(BatchAnnotateImagesRequest $request): BatchAnnotateImagesResponse;
}
