<?php

namespace App\Service\CrewImport;

use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportRow;

final class CrewImportInterpretedRowsAdapter
{
    /** @param list<CrewImportInterpretedRow> $rows */
    public function toExtraction(array $rows): CrewImportExtraction
    {
        $normalized = [];
        foreach ($rows as $index => $row) {
            $warnings = [];
            if ($row->catalogMismatch) {
                $warnings[] = CrewImportWarning::AI_CATALOG_MISMATCH;
            }
            if ($row->rowKind === CrewImportInterpretedRow::UNKNOWN) {
                $warnings[] = CrewImportWarning::AI_ROW_UNKNOWN;
            } elseif ($row->rowKind === CrewImportInterpretedRow::NON_CREW) {
                $warnings[] = CrewImportWarning::AI_NON_CREW;
            }

            $normalized[] = new CrewImportRow(
                $index + 2,
                $row->proposedName,
                $row->proposedLastName,
                $row->originalPosition,
                $row->originalDepartment,
                $row->email,
                $row->phone,
                $row->fullName,
                $row->departmentId,
                $row->positionId,
                $warnings,
                $row->sourceReference,
            );
        }

        return new CrewImportExtraction(CrewImportExtraction::OFFICIAL_TEMPLATE, $normalized);
    }
}
