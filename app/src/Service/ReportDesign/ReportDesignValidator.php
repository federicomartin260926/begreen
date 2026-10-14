<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final readonly class ReportDesignValidator
{
    private const STYLE_SCOPES = [
        '.report-design-cover',
        '.report-design-analytics',
        '.report-design-departments',
        '.report-design-ai-report',
        '.report-design-final-conclusion',
    ];

    public function __construct(
        private ReportDesignComponentCatalog $componentCatalog,
        private ReportDesignDataCatalog $dataCatalog,
    ) {
    }

    public function validate(string $html, string $css): void
    {
        $this->validateHtml($html);
        $this->validateCss($css);
    }

    private function validateHtml(string $html): void
    {
        if (
            str_contains($html, '{{')
            || str_contains($html, '{%')
            || str_contains($html, '{#')
        ) {
            throw new \InvalidArgumentException(
                'Report design may not contain Twig syntax.'
            );
        }

        preg_match_all(
            '/\[COMPONENT:([a-z0-9_-]+)\]/',
            $html,
            $componentMatches
        );

        $components = $componentMatches[1] ?? [];

        foreach ($components as $component) {
            if (!$this->componentCatalog->has($component)) {
                throw new \InvalidArgumentException(
                    'Report design references an unknown component.'
                );
            }
        }

        foreach ($this->componentCatalog->requiredKeys() as $required) {
            if (
                count(
                    array_filter(
                        $components,
                        static fn (string $component): bool =>
                            $component === $required
                    )
                ) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Report design must contain every required component exactly once.'
                );
            }
        }

        if (
            count($components)
            !== count($this->componentCatalog->requiredKeys())
        ) {
            throw new \InvalidArgumentException(
                'Report design contains duplicated components.'
            );
        }

        preg_match_all(
            '/\[\[([a-z0-9_.-]+)\]\]/',
            $html,
            $variableMatches
        );

        foreach (($variableMatches[1] ?? []) as $variable) {
            if (!$this->dataCatalog->has($variable)) {
                throw new \InvalidArgumentException(
                    'Report design references an unavailable variable.'
                );
            }
        }

        if (($variableMatches[1] ?? []) !== []) {
            throw new \InvalidArgumentException(
                'Report design may not render scalar variables outside trusted report components.'
            );
        }

        $remaining = preg_replace(
            '/\[COMPONENT:[a-z0-9_-]+\]/',
            '',
            $html
        );

        if (
            !is_string($remaining)
            || trim($remaining) !== ''
        ) {
            throw new \InvalidArgumentException(
                'Report design contains forbidden HTML or textual content.'
            );
        }
    }

    private function validateCss(string $css): void
    {
        if (trim($css) === '') {
            return;
        }

        if (
            preg_match(
                '/\b(?:font-family|font|line-height)\s*:/i',
                $css
            ) === 1
        ) {
            throw new \InvalidArgumentException(
                'Report design may not override the base font family or line height.'
            );
        }

        /*
         * Una propiedad geométrica peligrosa sólo cuenta cuando empieza
         * realmente una declaración CSS. Así evitamos falsos positivos
         * como border-top, border-left o border-bottom.
         */
        if (
            preg_match(
                '/(?:^|[;{])\s*(position|float|clear|overflow(?:-x|-y)?|height|min-height|max-height|top|right|bottom|left|transform)\s*:/i',
                $css,
                $unsafeGeometryMatch
            ) === 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Report design CSS may not use unsafe geometry properties (%s).',
                    strtolower($unsafeGeometryMatch[1])
                )
            );
        }

        $forbiddenPatterns = [
            '/@import\b/i' => '@import',
            '/@font-face\b/i' => '@font-face',
            '/@page\b/i' => '@page',
            '/@[a-z-]+\b/i' => 'CSS at-rules',
            '/url\s*\(/i' => 'url()',
            '/expression\s*\(/i' => 'expression()',
            '/behavior\s*:/i' => 'behavior:',
            '/-moz-binding\s*:/i' => '-moz-binding:',
            '/javascript\s*:/i' => 'javascript:',
            '/\b(?:page-break-(?:before|after|inside)|break-(?:before|after|inside))\s*:/i'
                => 'pagination properties',
            '/\bdisplay\s*:\s*(?:flex|inline-flex|grid|inline-grid)\b/i'
                => 'unsupported flex/grid layout',
            '/\bcontent\s*:/i' => 'generated content',
            '/[<>]/' => 'HTML-like angle brackets',
        ];

        foreach ($forbiddenPatterns as $pattern => $label) {
            if (preg_match($pattern, $css) === 1) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Report design CSS may not use %s.',
                        $label
                    )
                );
            }
        }

        $cleanCss = preg_replace(
            '~/\*.*?\*/~s',
            '',
            $css
        );

        if (!is_string($cleanCss)) {
            throw new \InvalidArgumentException(
                'Report design CSS is invalid.'
            );
        }

        preg_match_all(
            '/([^{}]+)\{([^{}]*)\}/s',
            $cleanCss,
            $rules,
            PREG_SET_ORDER
        );

        if ($rules === []) {
            throw new \InvalidArgumentException(
                'Report design CSS is invalid.'
            );
        }

        $unparsed = preg_replace(
            '/[^{}]+\{[^{}]*\}/s',
            '',
            $cleanCss
        );

        if (
            !is_string($unparsed)
            || trim($unparsed) !== ''
        ) {
            throw new \InvalidArgumentException(
                'Report design CSS is invalid.'
            );
        }

        foreach ($rules as $rule) {
            $selectorList = trim((string) ($rule[1] ?? ''));
            $declarations = trim((string) ($rule[2] ?? ''));

            foreach (explode(',', $selectorList) as $selector) {
                $selector = trim($selector);

                if (
                    $selector === ''
                    || str_contains($selector, '*')
                ) {
                    throw new \InvalidArgumentException(
                        'Report design CSS may not use universal selectors.'
                    );
                }

                $matchedScope = null;

                foreach (self::STYLE_SCOPES as $scope) {
                    if (
                        preg_match(
                            '/^'
                            .preg_quote($scope, '/')
                            .'(?=$|[\s.#:\[])/',
                            $selector
                        ) === 1
                    ) {
                        $matchedScope = $scope;
                        break;
                    }
                }

                if ($matchedScope === null) {
                    throw new \InvalidArgumentException(
                        'Report design CSS selectors must be scoped to an authorised report component.'
                    );
                }

                /*
                 * La raíz del hook ES la página física.
                 * Su geometría siempre pertenece a la plantilla estable.
                 *
                 * Los descendientes pueden usar display y anchuras para
                 * composición visual interna, pero no posición absoluta,
                 * alturas, overflow, transformaciones ni paginación.
                 */
                $scopeTail = substr(
                    $selector,
                    strlen($matchedScope)
                );

                $targetsPhysicalRoot =
                    $scopeTail === ''
                    || preg_match(
                        '/^[.#:\[]/',
                        $scopeTail
                    ) === 1;

                if (
                    $targetsPhysicalRoot
                    && preg_match(
                        '/(?:^|;)\s*(display|min-width|max-width|width)\s*:/i',
                        $declarations,
                        $geometryMatch
                    ) === 1
                ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Report design CSS may not change physical report root geometry with %s.',
                            strtolower($geometryMatch[1])
                        )
                    );
                }
            }
        }
    }
}
