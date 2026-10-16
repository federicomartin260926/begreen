<?php

namespace App\Service\Ai\Dto;

use App\Service\Ai\AiReportMeasureDecision;

final readonly class AiReportRequest
{
    /** @param list<AiReportCategory> $categories */
    public function __construct(
        public string $locale,
        public array $categories,
        public string $purpose = 'sustainability_plan',
        public array $factualContext = [],
        public array $sectionKeys = [],
    ) {
    }

    /** @return list<string> */
    public function categoryKeys(): array
    {
        if ($this->purpose === 'final_sustainability_report') {
            return $this->sectionKeys;
        }

        return array_map(static fn (AiReportCategory $category): string => $category->key, $this->categories);
    }

    /** @return list<string> */
    public function futureCategoryKeys(): array
    {
        if ($this->purpose === 'final_sustainability_report') {
            return [];
        }
        $keys = [];
        foreach ($this->categories as $category) {
            foreach ($category->measures as $measure) {
                if ($measure->decision === AiReportMeasureDecision::NOT_PLANNED) {
                    $keys[] = $category->key;
                    break;
                }
            }
        }

        return $keys;
    }
}
