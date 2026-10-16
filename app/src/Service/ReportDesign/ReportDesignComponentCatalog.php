<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final class ReportDesignComponentCatalog
{
    /**
     * @return array<string, array{
     *     label:string,
     *     description:string,
     *     template:string,
     *     required:bool
     * }>
     */
    public function all(?ReportDesignDefinition $definition = null): array
    {
        if ($definition?->document === ReportDesignCatalog::DOCUMENT_FINAL_SUSTAINABILITY_REPORT) {
            return $this->finalReportComponents();
        }

        return [
            'cover' => [
                'label' => 'Portada',
                'description' => 'Portada actual del Plan de Sostenibilidad.',
                'template' => 'backend/plan/pdf/_cover.html.twig',
                'required' => true,
            ],
            'analytics' => [
                'label' => 'Analítica',
                'description' => 'Resumen visual y métricas del plan.',
                'template' => 'backend/plan/pdf/_analytics.html.twig',
                'required' => true,
            ],
            'ai_report' => [
                'label' => 'Informe IA',
                'description' => 'Contenido narrativo generado para el plan.',
                'template' => 'backend/plan/pdf/_ai_report.html.twig',
                'required' => true,
            ],
            'departments' => [
                'label' => 'Departamentos y medidas',
                'description' => 'Detalle del plan organizado por departamentos.',
                'template' => 'backend/plan/pdf/_departments.html.twig',
                'required' => true,
            ],
            'final_conclusion' => [
                'label' => 'Conclusión final',
                'description' => 'Cierre y conclusión final del informe.',
                'template' => 'backend/plan/pdf/_final_conclusion.html.twig',
                'required' => true,
            ],
        ];
    }

    public function has(string $key, ?ReportDesignDefinition $definition = null): bool
    {
        return isset($this->all($definition)[$key]);
    }

    public function template(string $key, ?ReportDesignDefinition $definition = null): string
    {
        $component = $this->all($definition)[$key] ?? null;

        if ($component === null) {
            throw new \InvalidArgumentException('Unknown report design component.');
        }

        return $component['template'];
    }

    /** @return list<string> */
    public function requiredKeys(?ReportDesignDefinition $definition = null): array
    {
        $required = [];

        foreach ($this->all($definition) as $key => $component) {
            if ($component['required']) {
                $required[] = $key;
            }
        }

        return $required;
    }

    /** @return list<string> */
    public function styleScopes(?ReportDesignDefinition $definition = null): array
    {
        return array_map(
            static fn (string $key): string => '.report-design-'.str_replace('_', '-', $key),
            array_keys($this->all($definition)),
        );
    }

    /** @return array<string, array{label:string,description:string,template:string,required:bool}> */
    private function finalReportComponents(): array
    {
        $components = [
            'final-cover' => ['Portada', 'Portada y datos generales.'],
            'final-executive' => ['Resumen ejecutivo', 'Resumen ejecutivo editorial.'],
            'final-plan' => ['Plan', 'Resumen del Plan de Sostenibilidad.'],
            'final-implementation' => ['Implementación', 'Estados y resultados reales.'],
            'final-featured' => ['Medidas destacadas', 'Selección y relato editorial.'],
            'final-gallery' => ['Galería', 'Fotografías privadas seleccionadas.'],
            'final-emissions' => ['Emisiones', 'Emisiones válidas y desgloses.'],
            'final-ods-commitment' => ['ODS y compromiso', 'Asociaciones ODS y nivel de compromiso.'],
            'final-conclusion' => ['Aprendizajes y conclusión', 'Desviaciones, aprendizajes y cierre.'],
        ];
        $result = [];
        foreach ($components as $key => [$label, $description]) {
            $result[$key] = [
                'label' => $label,
                'description' => $description,
                'template' => 'backend/final_report/pdf/_'.str_replace('final-', '', $key).'.html.twig',
                'required' => true,
            ];
        }

        return $result;
    }
}
