<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;
use App\Entity\VerificationSource;

final class MeasureCatalogAdminService
{
    public const EXPECTED_TOTAL_MEASURES = 200;
    public const EXPECTED_TOTAL_POINTS = 565;
    public const EXPECTED_SCORE_DISTRIBUTION = [
        5 => 28,
        4 => 22,
        3 => 50,
        2 => 87,
        1 => 13,
    ];

    /**
     * @param iterable<Measure> $measures
     *
     * @return array{
     *     totalMeasures:int,
     *     totalPoints:int,
     *     scoreDistribution:array<int,int>,
     *     missingDepartments:int,
     *     missingOds:int,
     *     missingVerificationSources:int,
     *     missingImpactAreas:int,
     *     missingTripleBalanceAxes:int,
     *     isExpected:bool
     * }
     */
    public function summarizeCatalog(iterable $measures): array
    {
        $summary = [
            'totalMeasures' => 0,
            'totalPoints' => 0,
            'scoreDistribution' => [
                5 => 0,
                4 => 0,
                3 => 0,
                2 => 0,
                1 => 0,
            ],
            'missingDepartments' => 0,
            'missingOds' => 0,
            'missingVerificationSources' => 0,
            'missingImpactAreas' => 0,
            'missingTripleBalanceAxes' => 0,
            'isExpected' => false,
        ];

        foreach ($measures as $measure) {
            $summary['totalMeasures']++;

            $score = (int) ($measure->getScore() ?? 0);
            $summary['totalPoints'] += $score;
            $summary['scoreDistribution'][$score] = ($summary['scoreDistribution'][$score] ?? 0) + 1;

            if ($measure->getDepartments()->isEmpty()) {
                $summary['missingDepartments']++;
            }
            if ($measure->getOdsItems()->isEmpty()) {
                $summary['missingOds']++;
            }
            if ($measure->getVerificationSourceLinks()->isEmpty()) {
                $summary['missingVerificationSources']++;
            }
            if ($measure->getImpactAreas()->isEmpty()) {
                $summary['missingImpactAreas']++;
            }
            if ($measure->getTripleBalanceAxes()->isEmpty()) {
                $summary['missingTripleBalanceAxes']++;
            }
        }

        ksort($summary['scoreDistribution']);

        $summary['isExpected'] = $summary['totalMeasures'] > 0
            && $summary['missingDepartments'] === 0
            && $summary['missingOds'] === 0
            && $summary['missingVerificationSources'] === 0
            && $summary['missingImpactAreas'] === 0
            && $summary['missingTripleBalanceAxes'] === 0;

        return $summary;
    }

    /**
     * @param array<int, VerificationSource|null> $sourcesByPriority
     *
     * @return array<int, array{field:string, message:string}>
     */
    public function validateV23Measure(Measure $measure, array $sourcesByPriority): array
    {
        if (!$this->isCanonicalV23Measure($measure)) {
            return [];
        }

        $errors = [];
        $score = $measure->getScore();
        if ($score === null || $score < 1 || $score > 5) {
            $errors[] = [
                'field' => 'score',
                'message' => 'La puntuación de una medida v23 debe estar entre 1 y 5.',
            ];
        }

        if ($measure->getMeasureBlock() === null) {
            $errors[] = [
                'field' => 'measureBlock',
                'message' => 'La medida v23 debe tener un bloque.',
            ];
        }

        if ($measure->getDepartments()->isEmpty()) {
            $errors[] = [
                'field' => 'departments',
                'message' => 'La medida v23 debe tener al menos un departamento.',
            ];
        }

        if ($measure->getOdsItems()->isEmpty()) {
            $errors[] = [
                'field' => 'odsItems',
                'message' => 'La medida v23 debe tener al menos un ODS.',
            ];
        }

        if ($measure->getImpactAreas()->isEmpty()) {
            $errors[] = [
                'field' => 'impactAreas',
                'message' => 'La medida v23 debe tener al menos un área de impacto.',
            ];
        }

        if ($measure->getTripleBalanceAxes()->isEmpty()) {
            $errors[] = [
                'field' => 'tripleBalanceAxes',
                'message' => 'La medida v23 debe tener al menos un eje de triple balance.',
            ];
        }

        try {
            $normalizedSources = $this->normalizeSourcesByPriority($sourcesByPriority);
        } catch (\InvalidArgumentException $e) {
            $errors[] = [
                'field' => 'verificationSourcePriority1',
                'message' => $e->getMessage(),
            ];

            return $errors;
        }

        if ($normalizedSources === []) {
            $errors[] = [
                'field' => 'verificationSourcePriority1',
                'message' => 'La medida v23 debe tener al menos una fuente de verificación.',
            ];
        }

        return $errors;
    }

    /**
     * @param array<int, VerificationSource|null> $sourcesByPriority
     */
    public function syncVerificationSources(Measure $measure, array $sourcesByPriority): void
    {
        $normalized = $this->normalizeSourcesByPriority($sourcesByPriority);

        foreach ($measure->getVerificationSourceLinks()->toArray() as $existingLink) {
            $measure->removeVerificationSourceLink($existingLink);
        }

        foreach ($normalized as $item) {
            $link = new MeasureVerificationSource();
            $link
                ->setPriority($item['priority'])
                ->setVerificationSource($item['source']);
            $measure->addVerificationSourceLink($link);
        }

        $measure->setVerificationSources($this->buildVerificationSourcesSummary($normalized));
    }

    /**
     * @param array<int, VerificationSource|null> $sourcesByPriority
     *
     * @return array<int, array{priority:int, source:VerificationSource}>
     */
    private function normalizeSourcesByPriority(array $sourcesByPriority): array
    {
        $normalized = [];
        $seenSources = [];

        foreach ([1, 2, 3] as $priority) {
            $source = $sourcesByPriority[$priority] ?? null;
            if (!$source instanceof VerificationSource) {
                continue;
            }

            $sourceId = $source->getId();
            if ($sourceId !== null && isset($seenSources[$sourceId])) {
                throw new \InvalidArgumentException('No se puede repetir la misma fuente de verificación en varias prioridades.');
            }

            $normalized[] = [
                'priority' => $priority,
                'source' => $source,
            ];

            if ($sourceId !== null) {
                $seenSources[$sourceId] = true;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array{priority:int, source:VerificationSource}> $normalized
     */
    private function buildVerificationSourcesSummary(array $normalized): ?string
    {
        if ($normalized === []) {
            return null;
        }

        $parts = [];
        foreach ($normalized as $item) {
            $parts[] = sprintf('%d. %s', $item['priority'], $this->normalizeVerificationSourceName((string) $item['source']->getName()));
        }

        return implode(' | ', $parts);
    }

    private function normalizeVerificationSourceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (preg_match('/^\s*\d+\s*[\.\)\-:]\s*(.+)$/u', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }

    private function isCanonicalV23Measure(Measure $measure): bool
    {
        return $measure->getProtocol()?->getCode() === PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE
            && $measure->getImportVersion() === PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION;
    }
}
