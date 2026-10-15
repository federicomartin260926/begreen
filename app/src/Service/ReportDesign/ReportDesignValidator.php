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
    private const LOGO_CONTAINER_SELECTOR =
        '.report-design-cover .pdf-cover-company-logos';
    private const LOGO_CELL_SELECTOR =
        '.report-design-cover .pdf-cover-company-logos__cell';
    private const LOGO_IMAGE_SELECTOR =
        '.report-design-cover .pdf-cover-company-logos__image';
    private const LOGO_MIN_SIZE_LIMIT_MM = 8.0;
    private const LOGO_MAX_SIZE_LIMIT_MM = 40.0;
    private const LOGO_MAX_SPACING_MM = 2.5;

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

                $this->validateLogoRule($selector, $declarations);

                preg_match_all(
                    '/(?:^|;)\s*(position|float|clear|overflow(?:-x|-y)?|height|min-height|max-height|top|right|bottom|left|transform)\s*:\s*([^;]*)/i',
                    $declarations,
                    $unsafeGeometryMatches,
                    PREG_SET_ORDER
                );

                foreach ($unsafeGeometryMatches as $unsafeGeometryMatch) {
                    $property = strtolower($unsafeGeometryMatch[1]);
                    $value = trim($unsafeGeometryMatch[2]);

                    if (
                        $selector === self::LOGO_IMAGE_SELECTOR
                        && $property === 'height'
                        && preg_match(
                            '/^auto\s*!important$/i',
                            $value
                        ) === 1
                    ) {
                        continue;
                    }

                    throw new \InvalidArgumentException(
                        sprintf(
                            'Report design CSS may not use unsafe geometry properties (%s).',
                            $property
                        )
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

    private function validateLogoRule(
        string $selector,
        string $declarations,
    ): void {
        if (!str_contains($selector, '.pdf-cover-company-logos')) {
            return;
        }

        $allowedProperties = match ($selector) {
            self::LOGO_CONTAINER_SELECTOR => [
                'margin-left',
                'margin-right',
            ],
            self::LOGO_CELL_SELECTOR => ['padding-right'],
            self::LOGO_IMAGE_SELECTOR => ['max-width', 'height'],
            default => throw new \InvalidArgumentException(
                'Report design CSS may only target authorised logo selectors.'
            ),
        };

        $properties = [];

        foreach (explode(';', $declarations) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            if (
                preg_match(
                    '/^([a-z-]+)\s*:\s*(.+)$/i',
                    $declaration,
                    $matches
                ) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Report design logo CSS is invalid.'
                );
            }

            $property = strtolower($matches[1]);
            $value = trim($matches[2]);

            if (
                !in_array($property, $allowedProperties, true)
                || isset($properties[$property])
            ) {
                throw new \InvalidArgumentException(
                    'Report design logo CSS uses an unsupported property.'
                );
            }

            $properties[$property] = $value;

            match ($selector) {
                self::LOGO_CONTAINER_SELECTOR =>
                    $this->validateLogoAlignmentValue($value),
                self::LOGO_CELL_SELECTOR =>
                    $this->validateLogoSpacingValue($value),
                self::LOGO_IMAGE_SELECTOR =>
                    $this->validateLogoImageValue($property, $value),
            };
        }

        if (
            $selector === self::LOGO_IMAGE_SELECTOR
            && (
                !isset($properties['max-width'])
                || !isset($properties['height'])
            )
        ) {
            throw new \InvalidArgumentException(
                'Report design logo max-width and automatic height must be set together.'
            );
        }
    }

    private function validateLogoAlignmentValue(string $value): void
    {
        if (preg_match('/^(?:auto|0(?:mm)?)$/i', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Report design logo alignment must use only auto or 0.'
            );
        }
    }

    private function validateLogoSpacingValue(string $value): void
    {
        if (preg_match('/^(0|(?:\d+(?:\.\d+)?)mm)$/i', $value, $matches) !== 1) {
            throw new \InvalidArgumentException(
                'Report design logo spacing must use millimetres.'
            );
        }

        $spacing = (float) $matches[1];

        if ($spacing < 0 || $spacing > self::LOGO_MAX_SPACING_MM) {
            throw new \InvalidArgumentException(
                'Report design logo spacing is outside the safe range.'
            );
        }
    }

    private function validateLogoImageValue(
        string $property,
        string $value,
    ): void {
        if ($property === 'height') {
            if (preg_match('/^auto\s*!important$/i', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'Report design logo height must preserve aspect ratio.'
                );
            }

            return;
        }

        if (
            preg_match(
                '/^(\d+(?:\.\d+)?)mm\s*!important$/i',
                $value,
                $matches
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Report design logo max-width must use safe millimetres.'
            );
        }

        $sizeLimit = (float) $matches[1];

        if (
            $sizeLimit < self::LOGO_MIN_SIZE_LIMIT_MM
            || $sizeLimit > self::LOGO_MAX_SIZE_LIMIT_MM
        ) {
            throw new \InvalidArgumentException(
                'Report design logo max-width is outside the safe range.'
            );
        }
    }
}
