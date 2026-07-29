<?php

namespace App\Service;

final class MeasureTemplateSchema
{
    public const SHEET_TITLE = 'Plantilla estándar de medidas';
    public const LISTS_SHEET = 'Listas';
    public const MATRIX_SELECTION_MARKER = 'X';

    private const MATRIX_GROUP_LABELS = [
        'impact_areas' => 'Impacto ambiental',
        'departments' => 'Departamento',
        'verification_sources' => 'Fuente de verificación',
        'ods_items' => 'ODS',
        'triple_balance_axes' => 'Triple balance',
    ];

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
            'question_text' => 'Pregunta (futuro)',
            'gamification_message' => 'Mensaje de gamificación',
            'description' => 'Descripción',
            'implementation' => 'Implementación',
            'department_action_text' => 'Acción por departamento',
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
            'question_text_en' => 'Pregunta (futuro) EN (opcional)',
            'gamification_message_en' => 'Mensaje de gamificación EN (opcional)',
            'description_en' => 'Descripción EN (opcional)',
            'implementation_en' => 'Implementación EN (opcional)',
            'verification_sources_en' => 'Fuentes de verificación EN (opcional)',
            'department_action_text_en' => 'Acción por departamento EN (opcional)',
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
            'question_text',
            'question_text_en',
            'gamification_message',
            'gamification_message_en',
            'department_action_text',
            'department_action_text_en',
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function matrixGroupLabels(): array
    {
        return self::MATRIX_GROUP_LABELS;
    }

    public static function isMatrixGroupLabel(string $label): bool
    {
        return in_array(self::normalizeHeader($label), array_map(
            static fn (string $groupLabel): string => self::normalizeHeader($groupLabel),
            self::MATRIX_GROUP_LABELS
        ), true);
    }

    /**
     * @return array<string, string>
     */
    public static function scalarHeaderLookup(): array
    {
        $lookup = [];
        foreach (self::headers() as $key => $label) {
            if (isset(self::MATRIX_GROUP_LABELS[$key])) {
                continue;
            }

            $lookup[self::normalizeHeader($label)] = $key;
        }

        return $lookup;
    }

    /**
     * @return array<string, string>
     */
    public static function matrixGroupLookup(): array
    {
        $lookup = [];
        foreach (self::MATRIX_GROUP_LABELS as $key => $label) {
            $lookup[self::normalizeHeader($label)] = $key;
        }

        return $lookup;
    }

    public static function isSelectionMarker(?string $value): bool
    {
        return mb_strtoupper(trim((string) $value)) === self::MATRIX_SELECTION_MARKER;
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

    public static function canonicalEsgName(string $value): ?string
    {
        return match (trim($value)) {
            'Ambiental', 'Environmental', 'E' => 'Ambiental',
            'Social', 'S' => 'Social',
            'Gobernanza', 'Governance', 'G' => 'Gobernanza',
            default => null,
        };
    }

    public static function canonicalScopeName(string $value): ?string
    {
        return match (trim($value)) {
            'Alcance 1', '1' => 'Alcance 1',
            'Alcance 2', '2' => 'Alcance 2',
            'Alcance 3', '3' => 'Alcance 3',
            'No aplica', '-' => 'No aplica',
            'Alcance 1, 2 y 3', '1, 2 y 3' => 'Alcance 1, 2 y 3',
            default => null,
        };
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
