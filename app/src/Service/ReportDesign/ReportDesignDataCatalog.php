<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final class ReportDesignDataCatalog
{
    /**
     * @return array<string, array{
     *     group:string,
     *     group_label:string,
     *     label:string,
     *     type:string,
     *     description:string
     * }>
     */
    public function all(): array
    {
        return [
            'project.name' => [
                'group' => 'project',
                'group_label' => 'Proyecto',
                'label' => 'Nombre',
                'type' => 'text',
                'description' => 'Nombre del proyecto activo.',
            ],
            'report.generated_by' => [
                'group' => 'report',
                'group_label' => 'Informe',
                'label' => 'Generado por',
                'type' => 'text',
                'description' => 'Nombre mostrado del usuario que genera el informe.',
            ],
            'score.gained' => [
                'group' => 'score',
                'group_label' => 'Puntuación',
                'label' => 'Puntos obtenidos',
                'type' => 'number',
                'description' => 'Puntuación alcanzada por el plan.',
            ],
            'score.max' => [
                'group' => 'score',
                'group_label' => 'Puntuación',
                'label' => 'Puntuación máxima',
                'type' => 'number',
                'description' => 'Puntuación máxima disponible para el plan.',
            ],
            'score.percent' => [
                'group' => 'score',
                'group_label' => 'Puntuación',
                'label' => 'Porcentaje',
                'type' => 'percentage',
                'description' => 'Porcentaje de puntuación alcanzado por el plan.',
            ],
            'commitment.planned_percentage' => [
                'group' => 'commitment',
                'group_label' => 'Compromiso',
                'label' => 'Porcentaje planificado',
                'type' => 'percentage',
                'description' => 'Porcentaje redondeado del compromiso planificado.',
            ],
            'ai.general_conclusion' => [
                'group' => 'ai',
                'group_label' => 'Informe IA',
                'label' => 'Resumen general',
                'type' => 'text',
                'description' => 'Conclusión general generada para el Plan de Sostenibilidad.',
            ],
            'ai.final_conclusion' => [
                'group' => 'ai',
                'group_label' => 'Informe IA',
                'label' => 'Conclusión final',
                'type' => 'text',
                'description' => 'Conclusión final generada para el Plan de Sostenibilidad.',
            ],
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Árbol preparado para mostrarlo en la interfaz del diseñador.
     *
     * @return array<string, array{
     *     label:string,
     *     variables:array<string, array{label:string,type:string,description:string}>
     * }>
     */
    public function tree(): array
    {
        $tree = [];

        foreach ($this->all() as $key => $definition) {
            $group = $definition['group'];

            $tree[$group] ??= [
                'label' => $definition['group_label'],
                'variables' => [],
            ];

            $tree[$group]['variables'][$key] = [
                'label' => $definition['label'],
                'type' => $definition['type'],
                'description' => $definition['description'],
            ];
        }

        return $tree;
    }

    /**
     * Construye un snapshot escalar y seguro.
     *
     * Nunca se entregan a la IA objetos Doctrine completos.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar|null>
     */
    public function snapshot(array $context): array
    {
        $project = $context['project'] ?? null;

        $projectName = (
            is_object($project)
            && method_exists($project, 'getName')
        )
            ? (string) $project->getName()
            : '';

        $plannedPercentage =
            $context['commitmentSummary']['planned']['percentageRounded']
            ?? null;

        return [
            'project.name' => $projectName,
            'report.generated_by' => $this->scalar(
                $context['currentUserLabel'] ?? null
            ),
            'score.gained' => $this->scalar(
                $context['scoreGained'] ?? null
            ),
            'score.max' => $this->scalar(
                $context['scoreMax'] ?? null
            ),
            'score.percent' => $this->scalar(
                $context['scorePct'] ?? null
            ),
            'commitment.planned_percentage' => $this->scalar(
                $plannedPercentage
            ),
            'ai.general_conclusion' => $this->scalar(
                $context['aiGeneralConclusion'] ?? null
            ),
            'ai.final_conclusion' => $this->scalar(
                $context['aiFinalConclusion'] ?? null
            ),
        ];
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) || $value === null
            ? $value
            : null;
    }
}
