<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final readonly class ReportDesignValidator
{
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


        // Eliminamos únicamente los tokens autorizados.
        $remaining = preg_replace(
            [
                '/\[COMPONENT:[a-z0-9_-]+\]/',
                '/\[\[[a-z0-9_.-]+\]\]/',
            ],
            '',
            $html
        );

        if (!is_string($remaining)) {
            throw new \InvalidArgumentException(
                'Report design markup is invalid.'
            );
        }

        // El layout sólo admite wrappers visuales.
        $remaining = preg_replace(
            '/<\/?(?:div|section|span)(?:\s+class=(?:"[A-Za-z0-9 _-]*"|\'[A-Za-z0-9 _-]*\'))?\s*>/i',
            '',
            $remaining
        );

        if (!is_string($remaining) || trim($remaining) !== '') {
            throw new \InvalidArgumentException(
                'Report design contains forbidden HTML or textual content.'
            );
        }
    }

    private function validateCss(string $css): void
    {
        if (preg_match('/\b(?:font-family|font|line-height)\s*:/i', $css) === 1) {
            throw new \InvalidArgumentException(
                'Report design may not override the base font family or line height.'
            );
        }

        $forbiddenPatterns = [
            '/@import\b/i' => '@import',
            '/url\s*\(/i' => 'url()',
            '/expression\s*\(/i' => 'expression()',
            '/behavior\s*:/i' => 'behavior:',
            '/-moz-binding\s*:/i' => '-moz-binding:',
            '/javascript\s*:/i' => 'javascript:',
            '/@font-face\b/i' => '@font-face',
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
    }

}
