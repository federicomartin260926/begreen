<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

use Twig\Environment;

final readonly class ReportDesignRenderer
{
    public function __construct(
        private ReportDesignStorage $storage,
        private ReportDesignValidator $validator,
        private ReportDesignComponentCatalog $componentCatalog,
        private ReportDesignDataCatalog $dataCatalog,
        private Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{
     *     html:string,
     *     css:string,
     *     metadata:array<string,mixed>
     * }|null
     */
    public function renderApproved(
        ReportDesignDefinition $definition,
        array $context,
    ): ?array {
        $design = $this->storage->loadApproved($definition);

        return $design === null
            ? null
            : $this->renderDesign($definition, $design, $context);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{
     *     html:string,
     *     css:string,
     *     metadata:array<string,mixed>
     * }|null
     */
    public function renderDraft(
        ReportDesignDefinition $definition,
        array $context,
    ): ?array {
        $design = $this->storage->loadDraft($definition);

        return $design === null
            ? null
            : $this->renderDesign($definition, $design, $context);
    }

    /**
     * @param array{
     *     html:string,
     *     css:string,
     *     metadata:array<string,mixed>
     * } $design
     * @param array<string, mixed> $context
     *
     * @return array{
     *     html:string,
     *     css:string,
     *     metadata:array<string,mixed>
     * }
     */
    private function renderDesign(
        ReportDesignDefinition $definition,
        array $design,
        array $context,
    ): array {
        // Nunca confiamos únicamente en la validación realizada
        // cuando se guardó el diseño.
        $this->validator->validate(
            $design['html'],
            $design['css'],
            $definition,
        );

        $snapshot = $this->dataCatalog->snapshot($context, $definition);

        $rendered = preg_replace_callback(
            '/\[COMPONENT:([a-z0-9_-]+)\]|\[\[([a-z0-9_.-]+)\]\]/',
            function (array $matches) use ($context, $snapshot, $definition): string {
                $component = $matches[1] ?? '';

                if ($component !== '') {
                    return $this->twig->render(
                        $this->componentCatalog->template($component, $definition),
                        $context,
                    );
                }

                $variable = $matches[2] ?? '';

                if (
                    $variable === ''
                    || !$this->dataCatalog->has($variable, $definition)
                    || !array_key_exists($variable, $snapshot)
                ) {
                    throw new \RuntimeException(
                        'Report design variable could not be resolved.'
                    );
                }

                return $this->formatVariable(
                    $definition,
                    $variable,
                    $snapshot[$variable],
                );
            },
            $design['html'],
        );

        if (!is_string($rendered)) {
            throw new \RuntimeException(
                'Report design could not be rendered.'
            );
        }

        return [
            'html' => $rendered,
            'css' => $design['css'],
            'metadata' => $design['metadata'],
        ];
    }

    private function formatVariable(
        ReportDesignDefinition $reportDefinition,
        string $key,
        string|int|float|bool|null $value,
    ): string {
        if ($value === null) {
            return '';
        }

        $definition = $this->dataCatalog->all($reportDefinition)[$key] ?? null;

        if ($definition === null) {
            throw new \RuntimeException(
                'Report design variable definition is unavailable.'
            );
        }

        $formatted = match ($definition['type']) {
            'percentage' => $this->formatNumber($value).'%',
            'number' => $this->formatNumber($value),
            default => is_bool($value)
                ? ($value ? 'Sí' : 'No')
                : (string) $value,
        };

        return htmlspecialchars(
            $formatted,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }

    private function formatNumber(
        string|int|float|bool $value,
    ): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $numeric = (float) $value;

        if (floor($numeric) === $numeric) {
            return (string) (int) $numeric;
        }

        return rtrim(
            rtrim(
                number_format($numeric, 2, ',', ''),
                '0',
            ),
            ',',
        );
    }
}
