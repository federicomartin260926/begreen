<?php

namespace App\Service\Ai;

final class AiReportOutputSchema
{
    /**
     * @param list<string> $expectedCategoryKeys
     * @param list<string> $expectedFutureCategoryKeys
     *
     * @return array<string, mixed>
     */
    public function get(array $expectedCategoryKeys, array $expectedFutureCategoryKeys): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['generalConclusion', 'categorySummaries', 'categoryFutureSummaries', 'finalConclusion'],
            'properties' => [
                'generalConclusion' => ['type' => 'string'],
                'categorySummaries' => $this->categoryCollection($expectedCategoryKeys),
                'categoryFutureSummaries' => $this->categoryCollection($expectedFutureCategoryKeys),
                'finalConclusion' => ['type' => 'string'],
            ],
        ];
    }

    public function toValidatorData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach (['categorySummaries', 'categoryFutureSummaries'] as $field) {
            $normalized = $this->normalizeCollection($data[$field] ?? null);
            if ($normalized === null) {
                return $data;
            }

            $data[$field] = $normalized;
        }

        return $data;
    }

    /** @param list<string> $categoryKeys */
    private function categoryCollection(array $categoryKeys): array
    {
        $properties = [];
        foreach ($categoryKeys as $categoryKey) {
            $properties[$categoryKey] = [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['summary'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                ],
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $categoryKeys,
            'properties' => $properties,
        ];
    }

    /** @return list<array{categoryKey:string, summary:mixed}>|null */
    private function normalizeCollection(mixed $collection): ?array
    {
        if (!is_array($collection)) {
            return null;
        }
        if ($collection === []) {
            return [];
        }
        if (array_is_list($collection)) {
            return null;
        }

        $summaries = [];
        foreach ($collection as $categoryKey => $summary) {
            if (!is_string($categoryKey) || !is_array($summary) || array_keys($summary) !== ['summary']) {
                return null;
            }

            $summaries[] = [
                'categoryKey' => $categoryKey,
                'summary' => $summary['summary'],
            ];
        }

        return $summaries;
    }
}
