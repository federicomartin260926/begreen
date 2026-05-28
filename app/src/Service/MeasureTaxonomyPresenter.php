<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;

final class MeasureTaxonomyPresenter
{
    /**
     * @return array<int, array{id:int|null, code:string|null, name:string, displayName:string}>
     */
    public function departments(Measure $measure): array
    {
        return array_map(static function ($department): array {
            $displayName = method_exists($department, 'getDisplayName')
                ? (string) $department->getDisplayName()
                : (string) ($department->getName() ?? '');

            return [
                'id' => method_exists($department, 'getId') ? $department->getId() : null,
                'code' => method_exists($department, 'getCode') ? $department->getCode() : null,
                'name' => (string) ($department->getName() ?? $displayName),
                'displayName' => $displayName,
            ];
        }, $measure->getResolvedDepartments());
    }

    /**
     * @return array<int, array{id:int|null, code:string|null, name:string, label:string}>
     */
    public function odsItems(Measure $measure): array
    {
        return array_map(static function ($ods): array {
            $code = method_exists($ods, 'getCode') ? $ods->getCode() : null;
            $name = (string) ($ods->getName() ?? '');

            return [
                'id' => method_exists($ods, 'getId') ? $ods->getId() : null,
                'code' => $code,
                'name' => $name,
                'label' => $code ?: $name,
            ];
        }, $measure->getResolvedOdsItems());
    }

    /**
     * @return array<int, array{priority:int, code:string|null, name:string}>
     */
    public function verificationSourcesWithPriority(Measure $measure): array
    {
        $links = $measure->getResolvedVerificationSourceLinks();

        return array_map(static function (MeasureVerificationSource $link): array {
            $source = $link->getVerificationSource();

            return [
                'priority' => $link->getPriority(),
                'code' => $source?->getCode(),
                'name' => (string) ($source?->getName() ?? ''),
                'displayName' => self::normalizeVerificationSourceName((string) ($source?->getName() ?? '')),
            ];
        }, $links);
    }

    private static function normalizeVerificationSourceName(string $name): string
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

    /**
     * @return array<int, array{id:int|null, code:string|null, name:string}>
     */
    public function impactAreas(Measure $measure): array
    {
        return array_map(static function ($impactArea): array {
            return [
                'id' => method_exists($impactArea, 'getId') ? $impactArea->getId() : null,
                'code' => method_exists($impactArea, 'getCode') ? $impactArea->getCode() : null,
                'name' => (string) ($impactArea->getName() ?? ''),
            ];
        }, $measure->getResolvedImpactAreas());
    }

    /**
     * @return array<int, array{id:int|null, code:string|null, name:string}>
     */
    public function tripleBalanceAxes(Measure $measure): array
    {
        return array_map(static function ($axis): array {
            return [
                'id' => method_exists($axis, 'getId') ? $axis->getId() : null,
                'code' => method_exists($axis, 'getCode') ? $axis->getCode() : null,
                'name' => (string) ($axis->getName() ?? ''),
            ];
        }, $measure->getResolvedTripleBalanceAxes());
    }

    public function matchesDepartment(Measure $measure, int|string|null $departmentId): bool
    {
        if ($departmentId === null || $departmentId === '') {
            return true;
        }

        foreach ($measure->getResolvedDepartments() as $department) {
            if ((string) $department->getId() === (string) $departmentId) {
                return true;
            }
        }

        return false;
    }

    public function matchesOds(Measure $measure, int|string|null $odsId): bool
    {
        if ($odsId === null || $odsId === '') {
            return true;
        }

        foreach ($measure->getResolvedOdsItems() as $ods) {
            if ((string) $ods->getId() === (string) $odsId) {
                return true;
            }
        }

        return false;
    }

    public function matchesImpactArea(Measure $measure, int|string|null $impactAreaId): bool
    {
        if ($impactAreaId === null || $impactAreaId === '') {
            return true;
        }

        foreach ($measure->getResolvedImpactAreas() as $impactArea) {
            if ((string) $impactArea->getId() === (string) $impactAreaId) {
                return true;
            }
        }

        return false;
    }

    public function matchesTripleBalanceAxis(Measure $measure, int|string|null $axisId): bool
    {
        if ($axisId === null || $axisId === '') {
            return true;
        }

        foreach ($measure->getResolvedTripleBalanceAxes() as $axis) {
            if ((string) $axis->getId() === (string) $axisId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function matchesFilters(Measure $measure, array $filters): bool
    {
        return $this->matchesDepartment($measure, $filters['department'] ?? null)
            && $this->matchesOds($measure, $filters['ods'] ?? null)
            && $this->matchesImpactArea($measure, $filters['impact_area'] ?? null)
            && $this->matchesTripleBalanceAxis($measure, $filters['triple_balance_axis'] ?? null);
    }
}
