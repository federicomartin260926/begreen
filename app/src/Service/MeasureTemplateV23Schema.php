<?php

namespace App\Service;

final class MeasureTemplateV23Schema
{
    public const SHEET_TITLE = 'Plantilla v23';
    public const LISTS_SHEET = 'Listas';

    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'protocol' => 'Protocolo',
            'project_type' => 'Tipo de proyecto',
            'measure_block' => 'Bloque',
            'category' => 'Categoría',
            'category_ghg' => 'Categoría GHG',
            'name' => 'Medida',
            'name_review' => 'Nombre revisión',
            'description' => 'Descripción',
            'implementation' => 'Implementación',
            'score' => 'Puntuación',
            'mandatory' => 'Obligatoria',
            'departments' => 'Departamentos',
            'ods_items' => 'ODS',
            'esg' => 'ESG',
            'scope' => 'Alcance',
            'impact_areas' => 'Áreas de impacto',
            'triple_balance_axes' => 'Triple balance',
            'verification_sources' => 'Fuentes de verificación',
            'name_en' => 'Nombre EN (opcional)',
            'name_review_en' => 'Nombre revisión EN (opcional)',
            'description_en' => 'Descripción EN (opcional)',
            'implementation_en' => 'Implementación EN (opcional)',
            'verification_sources_en' => 'Fuentes de verificación EN (opcional)',
        ];
    }

    /**
     * @return string[]
     */
    public static function requiredHeaders(): array
    {
        return [
            'protocol',
            'project_type',
            'measure_block',
            'category',
            'category_ghg',
            'name',
            'name_review',
            'description',
            'implementation',
            'score',
            'mandatory',
            'departments',
            'ods_items',
            'esg',
            'scope',
            'impact_areas',
            'triple_balance_axes',
            'verification_sources',
        ];
    }

    public static function isOptionalHeader(string $key): bool
    {
        return in_array($key, [
            'name_en',
            'name_review_en',
            'description_en',
            'implementation_en',
            'verification_sources_en',
        ], true);
    }

    public static function normalizeHeader(string $header): string
    {
        $value = trim($header);
        if ($value === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }

        $ascii = mb_strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/u', '', $ascii);

        return $ascii ?? '';
    }

    /**
     * @return string[]
     */
    public static function lookupCandidates(string $value): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return [];
        }

        $candidates = [$value];

        foreach ([' - ', ' ('] as $delimiter) {
            if (str_contains($value, $delimiter)) {
                $prefix = trim(explode($delimiter, $value, 2)[0]);
                if ($prefix !== '') {
                    $candidates[] = $prefix;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return string[]
     */
    public static function splitMultiValueCell(?string $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:;|\R)\s*/u', $raw) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            $items[] = $part;
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int, array{priority:int, value:string}>
     */
    public static function splitVerificationSourcesCell(?string $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\||\R|;)\s*/u', $raw) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if (!preg_match('/^\s*(\d{1,2})\s*[\.\:\-)]?\s*(.+?)\s*$/u', $part, $matches)) {
                throw new \InvalidArgumentException(sprintf('Formato inválido de fuente de verificación: "%s".', $part));
            }

            $items[] = [
                'priority' => (int) $matches[1],
                'value' => trim($matches[2]),
            ];
        }

        usort($items, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $items;
    }
}
