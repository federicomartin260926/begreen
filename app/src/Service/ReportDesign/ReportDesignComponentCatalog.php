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
    public function all(): array
    {
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

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function template(string $key): string
    {
        $component = $this->all()[$key] ?? null;

        if ($component === null) {
            throw new \InvalidArgumentException('Unknown report design component.');
        }

        return $component['template'];
    }

    /** @return list<string> */
    public function requiredKeys(): array
    {
        $required = [];

        foreach ($this->all() as $key => $component) {
            if ($component['required']) {
                $required[] = $key;
            }
        }

        return $required;
    }
}
