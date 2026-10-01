<?php

namespace App\Service\CrewImport;

use App\Entity\Project;
use App\Exception\Ai\AiInvalidStructureException;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;

final readonly class CrewImportAiInterpreter implements CrewImportAiInterpreterInterface
{
    public const MAX_ROWS = 500;
    private const MAX_CONTEXT_BYTES = 300_000;

    public function __construct(
        private CrewImportAiProviderInterface $provider,
        private CrewImportAiOutputSchema $schema,
        private CrewCatalogContextProvider $catalogContextProvider,
    ) {
    }

    public function interpret(Project $project, CrewImportTabularDocument|string $content): array
    {
        $catalog = $this->catalogContextProvider->provide($project);
        try {
            $context = json_encode([
                'sourceType' => $content instanceof CrewImportTabularDocument ? 'spreadsheet' : 'pdf_text',
                'source' => $content,
                'catalog' => $this->compactCatalog($catalog),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new AiInvalidStructureException('Crew import context could not be encoded.', previous: $exception);
        }
        if (strlen($context) > self::MAX_CONTEXT_BYTES) {
            throw new AiInvalidStructureException('Crew import context exceeds its size limit.');
        }

        $result = $this->provider->request($this->instructions(), $context, $this->schema->get());

        return $this->validate($result, $catalog);
    }

    /**
     * @param array<string, mixed> $result
     * @param list<array{id: int, name: array{es: string, en: string}, positions: list<array{id: int, name: array{es: string, en: string}}>}> $catalog
     * @return list<CrewImportInterpretedRow>
     */
    private function validate(array $result, array $catalog): array
    {
        if (count($result) !== 1 || !array_key_exists('rows', $result) || !is_array($result['rows']) || count($result['rows']) > self::MAX_ROWS) {
            throw new AiInvalidStructureException('Crew AI output has an invalid row collection.');
        }

        $departments = [];
        $positions = [];
        foreach ($catalog as $department) {
            $departments[$department['id']] = true;
            foreach ($department['positions'] as $position) {
                $positions[$position['id']] = $department['id'];
            }
        }

        $rows = [];
        $keys = [
            'sourceReference', 'fullName', 'proposedName', 'proposedLastName',
            'email', 'phone', 'originalDepartment', 'originalPosition',
            'rowKind', 'departmentId', 'positionId',
        ];
        foreach ($result['rows'] as $data) {
            if (!is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
                throw new AiInvalidStructureException('Crew AI output contains an invalid row.');
            }
            $sourceReference = $this->string($data['sourceReference'], 128);
            $fullName = $this->string($data['fullName'], 255);
            $name = $this->string($data['proposedName'], 255);
            $lastName = $this->string($data['proposedLastName'], 255);
            $email = $this->string($data['email'], 320);
            $phone = $this->string($data['phone'], 100);
            $departmentText = $this->string($data['originalDepartment'], 255);
            $positionText = $this->string($data['originalPosition'], 255);
            $rowKind = $data['rowKind'];
            if (
                $sourceReference === ''
                || !is_string($rowKind)
                || !in_array($rowKind, [CrewImportInterpretedRow::CREW, CrewImportInterpretedRow::NON_CREW, CrewImportInterpretedRow::UNKNOWN], true)
            ) {
                throw new AiInvalidStructureException('Crew AI output contains invalid row metadata.');
            }

            $departmentId = $this->nullableId($data['departmentId']);
            $positionId = $this->nullableId($data['positionId']);
            $catalogMismatch = false;
            if ($departmentId !== null && !isset($departments[$departmentId])) {
                $departmentId = null;
                $positionId = null;
                $catalogMismatch = true;
            } elseif ($positionId !== null && ($departmentId === null || ($positions[$positionId] ?? null) !== $departmentId)) {
                $positionId = null;
                $catalogMismatch = true;
            }

            $rows[] = new CrewImportInterpretedRow(
                $sourceReference,
                $fullName,
                $name,
                $lastName,
                $email,
                $phone,
                $departmentText,
                $positionText,
                $rowKind,
                $departmentId,
                $positionId,
                $catalogMismatch,
            );
        }

        return $rows;
    }

    private function string(mixed $value, int $maxLength): string
    {
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new AiInvalidStructureException('Crew AI output contains an invalid string.');
        }

        return trim($value);
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            throw new AiInvalidStructureException('Crew AI output contains an invalid catalog ID.');
        }

        return $value;
    }

    /** @param list<array{id: int, name: array{es: string, en: string}, positions: list<array{id: int, name: array{es: string, en: string}}>}> $catalog */
    private function compactCatalog(array $catalog): array
    {
        return array_map(static fn (array $department): array => [
            'id' => $department['id'],
            'es' => $department['name']['es'],
            'en' => $department['name']['en'],
            'positions' => array_map(static fn (array $position): array => [
                'id' => $position['id'],
                'es' => $position['name']['es'],
                'en' => $position['name']['en'],
            ], $department['positions']),
        ], $catalog);
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You structure crew-list source content into the strict supplied JSON schema.
Never invent, merge, remove, or duplicate people. Preserve sourceReference and fullName.
Never invent or correct email/phone values, and never add country prefixes.
Name splitting is only a proposal. Interpret Spanish and English role labels.
Classify every person as crew, non_crew, or unknown. Keep ambiguous people as unknown.
Use only department and position IDs from the supplied scoped catalog.
A position must belong to its selected department. Unknown or ambiguous catalog matches use null IDs.
Do not create departments or positions. Return structured JSON only.
PROMPT;
    }
}
