<?php

namespace App\Service\Ai;

final class AiReportOutputSchema
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['generalConclusion', 'categorySummaries'],
            'properties' => [
                'generalConclusion' => ['type' => 'string'],
                'categorySummaries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['categoryKey', 'summary'],
                        'properties' => [
                            'categoryKey' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
