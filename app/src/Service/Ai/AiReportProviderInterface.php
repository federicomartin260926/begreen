<?php

namespace App\Service\Ai;

use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;

interface AiReportProviderInterface
{
    public function generate(AiReportRequest $request): AiReportResult;
}
