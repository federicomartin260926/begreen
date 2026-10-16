<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final class ReportDesignCatalog
{
    public const DOCUMENT_SUSTAINABILITY_PLAN = 'sustainability-plan';
    public const DOCUMENT_FINAL_SUSTAINABILITY_REPORT = 'final-sustainability-report';
    public const VARIANT_GENERAL = 'general';

    public function sustainabilityPlanGeneral(): ReportDesignDefinition
    {
        return new ReportDesignDefinition(
            self::DOCUMENT_SUSTAINABILITY_PLAN,
            self::VARIANT_GENERAL,
            'Plan de Sostenibilidad — PDF general',
        );
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

        return [
            $general->key() => $general,
            $finalReport->key() => $finalReport,
        ];
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
