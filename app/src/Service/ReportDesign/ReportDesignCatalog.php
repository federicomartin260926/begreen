<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final class ReportDesignCatalog
{
    public const DOCUMENT_SUSTAINABILITY_PLAN = 'sustainability-plan';
    public const DOCUMENT_FINAL_SUSTAINABILITY_REPORT = 'final-sustainability-report';
    public const VARIANT_GENERAL = 'general';
    public const VARIANT_DEPARTMENT = 'department';
    public const VARIANT_ODS = 'ods';
    public const VARIANT_IMPACT_AREA = 'impact-area';
    public const VARIANT_CATEGORY = 'category';
    public const VARIANT_TRIPLE_BALANCE = 'triple-balance';

    private const GROUPED_PLAN_VARIANTS = [
        'department' => self::VARIANT_DEPARTMENT,
        'ods' => self::VARIANT_ODS,
        'impact_area' => self::VARIANT_IMPACT_AREA,
        'category' => self::VARIANT_CATEGORY,
        'triple_balance' => self::VARIANT_TRIPLE_BALANCE,
    ];

    private const GROUPED_PLAN_LABELS = [
        'department' => 'Plan de Sostenibilidad — PDF por departamentos',
        'ods' => 'Plan de Sostenibilidad — PDF por ODS',
        'impact_area' => 'Plan de Sostenibilidad — PDF por áreas de impacto',
        'category' => 'Plan de Sostenibilidad — PDF por categorías',
        'triple_balance' => 'Plan de Sostenibilidad — PDF por triple balance',
    ];

    public function sustainabilityPlanGeneral(): ReportDesignDefinition
    {
        return new ReportDesignDefinition(
            self::DOCUMENT_SUSTAINABILITY_PLAN,
            self::VARIANT_GENERAL,
            'Plan de Sostenibilidad — PDF general',
        );
    }

    public function sustainabilityPlanDepartment(): ReportDesignDefinition
    {
        return $this->sustainabilityPlanGrouped('department');
    }

    public function sustainabilityPlanGrouped(string $grouping): ReportDesignDefinition
    {
        $variant = self::GROUPED_PLAN_VARIANTS[$grouping] ?? null;
        $label = self::GROUPED_PLAN_LABELS[$grouping] ?? null;
        if ($variant === null || $label === null) {
            throw new \InvalidArgumentException('Unknown grouped sustainability plan design.');
        }

        return new ReportDesignDefinition(
            self::DOCUMENT_SUSTAINABILITY_PLAN,
            $variant,
            $label,
        );
    }

    public static function groupingForVariant(string $variant): ?string
    {
        $grouping = array_search($variant, self::GROUPED_PLAN_VARIANTS, true);

        return is_string($grouping) ? $grouping : null;
    }

    public static function isGroupedPlanDefinition(?ReportDesignDefinition $definition): bool
    {
        return $definition?->document === self::DOCUMENT_SUSTAINABILITY_PLAN
            && self::groupingForVariant($definition->variant) !== null;
    }

    public function finalSustainabilityReportGeneral(): ReportDesignDefinition
    {
        return new ReportDesignDefinition(
            self::DOCUMENT_FINAL_SUSTAINABILITY_REPORT,
            self::VARIANT_GENERAL,
            'Informe Final de Sostenibilidad — PDF general',
        );
    }

    /**
     * @return array<string, ReportDesignDefinition>
     */
    public function all(): array
    {
        $general = $this->sustainabilityPlanGeneral();
        $finalReport = $this->finalSustainabilityReportGeneral();

        $definitions = [
            $general->key() => $general,
            $finalReport->key() => $finalReport,
        ];
        foreach (array_keys(self::GROUPED_PLAN_VARIANTS) as $grouping) {
            $definition = $this->sustainabilityPlanGrouped($grouping);
            $definitions[$definition->key()] = $definition;
        }

        return $definitions;
    }

    public function get(string $document, string $variant): ReportDesignDefinition
    {
        $key = $document.'/'.$variant;
        $definitions = $this->all();

        if (!isset($definitions[$key])) {
            throw new \InvalidArgumentException('Unknown report design.');
        }

        return $definitions[$key];
    }
}
