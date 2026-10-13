<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final class ReportDesignCatalog
{
    public const DOCUMENT_SUSTAINABILITY_PLAN = 'sustainability-plan';
    public const VARIANT_GENERAL = 'general';

    public function sustainabilityPlanGeneral(): ReportDesignDefinition
    {
        return new ReportDesignDefinition(
            self::DOCUMENT_SUSTAINABILITY_PLAN,
            self::VARIANT_GENERAL,
            'Plan de Sostenibilidad — PDF general',
        );
    }

    /**
     * @return array<string, ReportDesignDefinition>
     */
    public function all(): array
    {
        $general = $this->sustainabilityPlanGeneral();

        return [
            $general->key() => $general,
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
