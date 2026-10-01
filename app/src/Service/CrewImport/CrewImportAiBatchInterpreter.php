<?php

namespace App\Service\CrewImport;

use App\Entity\Project;
use App\Exception\Ai\AiInvalidStructureException;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;
use App\Service\CrewImport\Dto\CrewImportTabularRow;
use App\Service\CrewImport\Dto\CrewImportTabularSheet;

final readonly class CrewImportAiBatchInterpreter
{
    public const BATCH_SIZE = 30;
    private const GLOBAL_CONTEXT_SIZE = 5;
    private const LOCAL_CONTEXT_SIZE = 3;

    public function __construct(private CrewImportAiInterpreterInterface $interpreter)
    {
    }

    /** @return list<CrewImportInterpretedRow> */
    public function interpret(Project $project, CrewImportTabularDocument $document): array
    {
        $interpreted = [];

        foreach ($document->sheets as $sheet) {
            foreach (array_chunk($sheet->rows, self::BATCH_SIZE) as $batchIndex => $rows) {
                $offset = $batchIndex * self::BATCH_SIZE;
                $targets = $this->referencedRows($sheet->name, $rows);
                $targetReferences = array_fill_keys(
                    array_map(static fn (CrewImportTabularRow $row): string => $row->sourceReference, $targets),
                    true,
                );
                $context = $this->contextRows($sheet, $offset, $targetReferences);
                $batch = new CrewImportTabularDocument([
                    new CrewImportTabularSheet($sheet->name, [], $context, $targets),
                ]);

                $result = $this->interpreter->interpret($project, $batch);
                foreach ($result as $row) {
                    if (!isset($targetReferences[$row->sourceReference])) {
                        throw new AiInvalidStructureException('Crew AI output references a non-target spreadsheet row.');
                    }
                    $interpreted[] = $row;
                }
            }
        }

        return $interpreted;
    }

    /**
     * @param list<CrewImportTabularRow> $rows
     * @return list<CrewImportTabularRow>
     */
    private function referencedRows(string $sheetName, array $rows): array
    {
        return array_map(
            static fn (CrewImportTabularRow $row): CrewImportTabularRow => new CrewImportTabularRow(
                $row->rowNumber,
                $row->cells,
                sprintf('%s!%d', $sheetName, $row->rowNumber),
            ),
            $rows,
        );
    }

    /**
     * @param array<string, true> $targetReferences
     * @return list<CrewImportTabularRow>
     */
    private function contextRows(CrewImportTabularSheet $sheet, int $offset, array $targetReferences): array
    {
        $rows = [
            ...array_slice($sheet->rows, 0, self::GLOBAL_CONTEXT_SIZE),
            ...array_slice($sheet->rows, max(0, $offset - self::LOCAL_CONTEXT_SIZE), min(self::LOCAL_CONTEXT_SIZE, $offset)),
        ];
        $context = [];

        foreach ($this->referencedRows($sheet->name, $rows) as $row) {
            if (!isset($targetReferences[$row->sourceReference])) {
                $context[$row->sourceReference] = $row;
            }
        }

        return array_values($context);
    }
}
