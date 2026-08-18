<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\ConfigurationReviewResource;
use App\Modules\Production\Services\ConfigurationReviewService;

/**
 * GET production/configuration/review — what a person still has to settle
 * before every packing posts as one known Tally item (P5-03). Read-only;
 * production.view suffices. The links themselves are made through the
 * existing packaging / attach-item / item endpoints.
 */
class ConfigurationReviewController extends Controller
{
    public function __construct(private readonly ConfigurationReviewService $review) {}

    public function __invoke(): ConfigurationReviewResource
    {
        return ConfigurationReviewResource::make($this->review->review());
    }
}
