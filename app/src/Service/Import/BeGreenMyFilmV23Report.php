<?php

namespace App\Service\Import;

use JsonSerializable;

final class BeGreenMyFilmV23Report implements JsonSerializable
{
    public const EXPECTED_MEASURES = 200;
    public const EXPECTED_POINTS = 565;
    public const EXPECTED_SCORE_DISTRIBUTION = [
        5 => 28,
        4 => 22,
        3 => 50,
        2 => 87,
        1 => 13,
    ];

    private string $status = 'OK';
    private array $warnings = [];
    private array $errors = [];

    private array $categories = [];
    private array $blocks = [];
    private array $departments = [];
    private array $verificationSources = [];
    private array $ods = [];
    private array $impactAreas = [];
    private array $tripleBalanceAxes = [];
    private array $measureRows = [];
    private array $sectionRows = [];
    private array $scoreDistribution = [
        5 => 0,
        4 => 0,
        3 => 0,
        2 => 0,
        1 => 0,
    ];

    private int $measureCount = 0;
    private int $totalPoints = 0;
    private ?string $sheetName = null;
    private ?string $dimension = null;
    private array $headers = [];

    public function setSheetName(string $sheetName): void
    {
        $this->sheetName = $sheetName;
    }

    public function setDimension(?string $dimension): void
    {
        $this->dimension = $dimension;
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function addWarning(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = compact('code', 'message', 'context');
    }

    public function addError(string $code, string $message, array $context = []): void
    {
        $this->errors[] = compact('code', 'message', 'context');
    }

    public function registerCategory(string $code, string $name, int $row): void
    {
        $this->registerItem($this->categories, $code, $name, $row);
    }

    public function registerBlock(string $code, string $name, int $row, ?string $parentCode = null, ?string $parentName = null): void
    {
        if (!isset($this->blocks[$code])) {
            $this->blocks[$code] = [
                'code' => $code,
                'name' => $name,
                'sourceRow' => $row,
                'sortOrder' => count($this->blocks) + 1,
                'parentCode' => $parentCode,
                'parentName' => $parentName,
                'count' => 0,
                'rows' => [],
            ];
        }
    }

    public function registerMeasure(array $measure, ?string $blockCode = null): void
    {
        $this->measureRows[$measure['row']] = $measure;
        $this->measureCount++;
        $this->totalPoints += (int) $measure['score'];
        $score = (int) $measure['score'];
        if (isset($this->scoreDistribution[$score])) {
            $this->scoreDistribution[$score]++;
        }

        if ($blockCode !== null && isset($this->blocks[$blockCode])) {
            $this->blocks[$blockCode]['count']++;
            $this->blocks[$blockCode]['rows'][] = $measure['row'];
        }
    }

    public function registerSection(string $code, string $name, int $row, int $level, ?string $parentCode, ?string $parentName): void
    {
        if (!isset($this->sectionRows[$row])) {
            $this->sectionRows[$row] = [
                'code' => $code,
                'name' => $name,
                'row' => $row,
                'level' => $level,
                'parentCode' => $parentCode,
                'parentName' => $parentName,
            ];
        }

        $this->registerBlock($code, $name, $row, $parentCode, $parentName);
    }

    public function registerDepartment(string $code, string $name, int $row): void
    {
        $this->registerItem($this->departments, $code, $name, $row);
    }

    public function registerVerificationSource(string $code, string $name, int $row, int $priority): void
    {
        if (!isset($this->verificationSources[$code])) {
            $this->verificationSources[$code] = [
                'code' => $code,
                'name' => $name,
                'firstRow' => $row,
                'count' => 0,
                'rows' => [],
            ];
        }

        $this->verificationSources[$code]['count']++;
        $this->verificationSources[$code]['rows'][] = ['row' => $row, 'priority' => $priority];
    }

    public function registerOds(string $code, string $name, int $row): void
    {
        $this->registerItem($this->ods, $code, $name, $row);
    }

    public function registerImpactArea(string $code, string $name, int $row): void
    {
        $this->registerItem($this->impactAreas, $code, $name, $row);
    }

    public function registerTripleBalanceAxis(string $code, string $name, int $row): void
    {
        $this->registerItem($this->tripleBalanceAxes, $code, $name, $row);
    }

    public function finalize(): void
    {
        foreach (self::EXPECTED_SCORE_DISTRIBUTION as $score => $expected) {
            $actual = $this->scoreDistribution[$score] ?? 0;
            if ($actual !== $expected) {
                $this->addError(
                    'score_distribution_mismatch',
                    sprintf('Distribución de puntuación incorrecta para %d puntos: esperado %d, obtenido %d.', $score, $expected, $actual),
                    ['score' => $score, 'expected' => $expected, 'actual' => $actual]
                );
            }
        }

        if ($this->measureCount !== self::EXPECTED_MEASURES) {
            $this->addError(
                'measure_count_mismatch',
                sprintf('Conteo de medidas incorrecto: esperado %d, obtenido %d.', self::EXPECTED_MEASURES, $this->measureCount),
                ['expected' => self::EXPECTED_MEASURES, 'actual' => $this->measureCount]
            );
        }

        if ($this->totalPoints !== self::EXPECTED_POINTS) {
            $this->addError(
                'points_mismatch',
                sprintf('Total de puntos incorrecto: esperado %d, obtenido %d.', self::EXPECTED_POINTS, $this->totalPoints),
                ['expected' => self::EXPECTED_POINTS, 'actual' => $this->totalPoints]
            );
        }

        if ($this->hasErrors()) {
            $this->status = 'FAILED';
            return;
        }

        $this->status = $this->hasWarnings() ? 'WARNING' : 'OK';
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMeasureCount(): int
    {
        return $this->measureCount;
    }

    public function getTotalPoints(): int
    {
        return $this->totalPoints;
    }

    public function getScoreDistribution(): array
    {
        return $this->scoreDistribution;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'sheetName' => $this->sheetName,
            'dimension' => $this->dimension,
            'headers' => $this->headers,
            'measureCount' => $this->measureCount,
            'totalPoints' => $this->totalPoints,
            'scoreDistribution' => $this->scoreDistribution,
            'categories' => $this->sortedValues($this->categories),
            'blocks' => $this->sortedValues($this->blocks),
            'departments' => $this->sortedValues($this->departments),
            'verificationSources' => $this->sortedValues($this->verificationSources),
            'ods' => $this->sortedValues($this->ods),
            'impactAreas' => $this->sortedValues($this->impactAreas),
            'tripleBalanceAxes' => $this->sortedValues($this->tripleBalanceAxes),
            'measureRows' => array_values($this->measureRows),
            'sectionRows' => array_values($this->sectionRows),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    private function registerItem(array &$bucket, string $code, string $name, int $row): void
    {
        if (!isset($bucket[$code])) {
            $bucket[$code] = [
                'code' => $code,
                'name' => $name,
                'firstRow' => $row,
                'count' => 0,
                'rows' => [],
            ];
        }

        $bucket[$code]['count']++;
        $bucket[$code]['rows'][] = $row;
    }

    private function sortedValues(array $bucket): array
    {
        $values = array_values($bucket);
        usort($values, static function (array $a, array $b): int {
            if (isset($a['sortOrder'], $b['sortOrder']) && $a['sortOrder'] !== $b['sortOrder']) {
                return $a['sortOrder'] <=> $b['sortOrder'];
            }

            if (isset($a['firstRow'], $b['firstRow']) && $a['firstRow'] !== $b['firstRow']) {
                return $a['firstRow'] <=> $b['firstRow'];
            }

            return ($a['count'] <=> $b['count']) ?: strcmp($a['name'], $b['name']);
        });
        return $values;
    }
}
