<?php

namespace App\Service\Ai;

final class AiReportOutputSchema
{
    /**
     * @param list<string> $expectedCategoryKeys
     *
     * @return array<string, mixed>
     */
    public function get(array $expectedCategoryKeys): array
    {
        $categoryProperties = [];
        foreach ($expectedCategoryKeys as $categoryKey) {
            $categoryProperties[$categoryKey] = [
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
            'required' => ['generalConclusion', 'categorySummaries', 'finalConclusion'],
            'properties' => [
                'generalConclusion' => ['type' => 'string'],
                'categorySummaries' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $expectedCategoryKeys,
                    'properties' => $categoryProperties,
                ],
                'finalConclusion' => ['type' => 'string'],
            ],
        ];
    }

    public function toValidatorData(mixed $data): mixed
    {
        if (
            !is_array($data)
            || !is_array($data['categorySummaries'] ?? null)
            || array_is_list($data['categorySummaries'])
        ) {
            return $data;
        }

        $summaries = [];
        foreach ($data['categorySummaries'] as $categoryKey => $summary) {
            if (!is_string($categoryKey) || !is_array($summary) || array_keys($summary) !== ['summary']) {
                return $data;
            }

            $summaries[] = [
                'categoryKey' => $categoryKey,
                'summary' => $summary['summary'],
            ];
        }

        $data['categorySummaries'] = $summaries;

        return $data;
    }
}
