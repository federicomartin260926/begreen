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

    /**
     * OpenAI structured output can safely bind every expected category
     * explicitly. This prevents omissions and duplicate category keys.
     *
     * @param list<string> $expectedCategoryKeys
     * @param list<string> $expectedFutureCategoryKeys
     *
     * @return array<string, mixed>
     */
    public function getForOpenAi(
        array $expectedCategoryKeys,
        array $expectedFutureCategoryKeys
    ): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'generalConclusion',
                'categorySummaries',
                'categoryFutureSummaries',
                'finalConclusion',
            ],
            'properties' => [
                'generalConclusion' => ['type' => 'string'],
                'categorySummaries' => $this->strictCategoryCollection(
                    $expectedCategoryKeys
                ),
                'categoryFutureSummaries' => $this->strictCategoryCollection(
                    $expectedFutureCategoryKeys
                ),
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
        // Keep the provider grammar deliberately compact.
        //
        // Cardinality is constrained here so structured-output providers cannot
        // omit categories. Exact keys, duplicates and omissions are still
        // validated after generation by AiReportResultValidator.
        //
        // Encoding every category as a separate JSON Schema property makes
        // Anthropic's compiled grammar grow with the plan and can exceed its
        // provider limit.
        $categoryKeySchema = ['type' => 'string'];

        if ($categoryKeys !== []) {
            $categoryKeySchema['enum'] = $categoryKeys;
        }

        $expectedCount = count($categoryKeys);

        return [
            'type' => 'array',
            'minItems' => $expectedCount,
            'maxItems' => $expectedCount,
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['categoryKey', 'summary'],
                'properties' => [
                    'categoryKey' => $categoryKeySchema,
                    'summary' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $categoryKeys
     *
     * @return array<string, mixed>
     */
    private function strictCategoryCollection(array $categoryKeys): array
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
