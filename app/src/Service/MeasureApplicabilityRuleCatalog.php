<?php

namespace App\Service;

use App\Entity\Measure;
use RuntimeException;

final class MeasureApplicabilityRuleCatalog
{
    /** @var array<string, array{filters:list<array<string, mixed>>, measures:array<string, array<string, mixed>>}>|null */
    private ?array $protocols = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return list<array<string, mixed>> */
    public function filtersForProtocol(?string $protocolCode): array
    {
        if ($protocolCode === null) {
            return [];
        }

        return $this->load()[$protocolCode]['filters'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function ruleForMeasure(Measure $measure): ?array
    {
        $protocolCode = $measure->getProtocol()?->getCode();
        if ($protocolCode === null) {
            return null;
        }

        $key = $protocolCode === 'be-green-my-animation'
            ? trim((string) $measure->getCatalogId())
            : (string) max(0, (int) $measure->getSourceRow() - 2);

        return $this->load()[$protocolCode]['measures'][$key] ?? null;
    }

    public function allowsDecision(Measure $measure, string $decision): bool
    {
        if (!in_array($decision, ['true', 'false', 'na'], true)) {
            return false;
        }

        $rule = $this->ruleForMeasure($measure);
        $mode = trim((string) ($rule['responseMode'] ?? ''));
        if ($mode === '') {
            return $decision !== 'na';
        }

        if (str_starts_with($mode, 'SÍ — BLOQUEADO')) {
            return $decision === 'true';
        }

        if ($decision === 'na') {
            if (str_starts_with($mode, 'N/A CONDICIONAL')) {
                return true;
            }

            // A03/A05/A06 are contextual profile answers, not binary exclusion
            // switches. Their dependent measures need a provisional, justified
            // N/A path when the per-measure objective condition is absent.
            if (str_starts_with($mode, 'SÍ / NO — N/A SOLO POR FILTRO')) {
                foreach ((array) ($rule['filterCodes'] ?? []) as $code) {
                    if (in_array($code, ['A03', 'A05', 'A06'], true)) {
                        return true;
                    }
                }
            }

            return false;
        }

        return true;
    }

    /** @return array<string, array{filters:list<array<string, mixed>>, measures:array<string, array<string, mixed>>}> */
    private function load(): array
    {
        if ($this->protocols !== null) {
            return $this->protocols;
        }

        $path = $this->projectDir.'/config/measure_applicability_rules.json';
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['protocols'] ?? null)) {
            throw new RuntimeException('El catálogo de reglas de aplicabilidad no es válido.');
        }

        return $this->protocols = $decoded['protocols'];
    }
}
