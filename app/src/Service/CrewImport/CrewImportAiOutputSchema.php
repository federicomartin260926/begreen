<?php

namespace App\Service\CrewImport;

final class CrewImportAiOutputSchema
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        $string = ['type' => 'string'];
        $nullableId = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rows'],
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'sourceReference', 'fullName', 'proposedName', 'proposedLastName',
                            'email', 'phone', 'originalDepartment', 'originalPosition',
                            'rowKind', 'departmentId', 'positionId',
                        ],
                        'properties' => [
                            'sourceReference' => $string,
                            'fullName' => $string,
                            'proposedName' => $string,
                            'proposedLastName' => $string,
                            'email' => $string,
                            'phone' => $string,
                            'originalDepartment' => $string,
                            'originalPosition' => $string,
                            'rowKind' => ['type' => 'string', 'enum' => ['crew', 'non_crew', 'unknown']],
                            'departmentId' => $nullableId,
                            'positionId' => $nullableId,
                        ],
                    ],
                ],
            ],
        ];
    }
}
