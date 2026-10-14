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
            '/\b(?:position|display|float|clear|overflow(?:-x|-y)?|width|min-width|max-width|height|min-height|max-height|top|right|bottom|left|transform)\s*:/i'
                => 'physical geometry properties',
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

                $isScoped = false;

                foreach (self::STYLE_SCOPES as $scope) {
                    if (
                        preg_match(
                            '/^'
                            .preg_quote($scope, '/')
                            .'(?=$|[\s.#:\[])/',
                            $selector
                        ) === 1
                    ) {
                        $isScoped = true;
                        break;
                    }
                }

                if (!$isScoped) {
                    throw new \InvalidArgumentException(
                        'Report design CSS selectors must be scoped to an authorised report component.'
                    );
                }
            }
        }
    }
}
