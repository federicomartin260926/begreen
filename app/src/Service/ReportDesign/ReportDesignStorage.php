<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ReportDesignStorage
{
    private const MAX_HTML_BYTES = 500_000;
    private const MAX_CSS_BYTES = 500_000;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/report-designs')]
        private readonly string $storageDirectory,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{html:string,css:string,metadata:array<string,mixed>}
     */
    public function saveDraft(
        ReportDesignDefinition $definition,
        string $html,
        string $css,
        array $metadata = [],
    ): array {
        return $this->saveSnapshot(
            $definition,
            'draft',
            $html,
            $css,
            $metadata,
        );
    }

    /**
     * @return array{html:string,css:string,metadata:array<string,mixed>}|null
     */
    public function loadDraft(
        ReportDesignDefinition $definition,
    ): ?array {
        return $this->loadSnapshot($definition, 'draft');
    }

    /**
     * @return array{html:string,css:string,metadata:array<string,mixed>}|null
     */
    public function loadApproved(
        ReportDesignDefinition $definition,
    ): ?array {
        return $this->loadSnapshot($definition, 'approved');
    }

    /**
     * @return array{html:string,css:string,metadata:array<string,mixed>}
     */
    public function approve(
        ReportDesignDefinition $definition,
    ): array {
        $draft = $this->loadDraft($definition);

        if ($draft === null) {
            throw new \RuntimeException(
                'There is no draft report design to approve.'
            );
        }

        $metadata = $draft['metadata'];

        unset(
            $metadata['updated_at'],
            $metadata['html_sha256'],
            $metadata['css_sha256'],
        );

        $metadata['approved_at'] =
            (new \DateTimeImmutable())->format(DATE_ATOM);
        $metadata['source'] = 'draft';

        return $this->saveSnapshot(
            $definition,
            'approved',
            $draft['html'],
            $draft['css'],
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{html:string,css:string,metadata:array<string,mixed>}
     */
    private function saveSnapshot(
        ReportDesignDefinition $definition,
        string $stage,
        string $html,
        string $css,
        array $metadata,
    ): array {
        $this->assertStage($stage);
        $this->assertContent($html, $css);

        $directory = $this->stageDirectory($definition, $stage);
        $this->ensureDirectory($directory);

        $storedMetadata = [
            'document' => $definition->document,
            'variant' => $definition->variant,
            'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'html_sha256' => hash('sha256', $html),
            'css_sha256' => hash('sha256', $css),
        ] + $metadata;

        $json = json_encode(
            $storedMetadata,
            JSON_THROW_ON_ERROR
            | JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        )."\n";

        $this->writeAtomically($directory.'/design.html', $html);
        $this->writeAtomically($directory.'/design.css', $css);

        // Metadata se activa al final y actúa como marcador
        // de snapshot completo.
        $this->writeAtomically($directory.'/metadata.json', $json);

        return [
            'html' => $html,
            'css' => $css,
            'metadata' => $storedMetadata,
        ];
    }

    /**
     * @return array{html:string,css:string,metadata:array<string,mixed>}|null
     */
    private function loadSnapshot(
        ReportDesignDefinition $definition,
        string $stage,
    ): ?array {
        $this->assertStage($stage);

        $directory = $this->stageDirectory($definition, $stage);

        $htmlPath = $directory.'/design.html';
        $cssPath = $directory.'/design.css';
        $metadataPath = $directory.'/metadata.json';

        $existing = array_filter([
            is_file($htmlPath),
            is_file($cssPath),
            is_file($metadataPath),
        ]);

        if ($existing === []) {
            return null;
        }

        if (
            !is_file($htmlPath)
            || !is_file($cssPath)
            || !is_file($metadataPath)
        ) {
            throw new \RuntimeException(
                'Stored report design is incomplete.'
            );
        }

        $html = file_get_contents($htmlPath);
        $css = file_get_contents($cssPath);
        $metadataJson = file_get_contents($metadataPath);

        if (
            $html === false
            || $css === false
            || $metadataJson === false
        ) {
            throw new \RuntimeException(
                'Stored report design could not be read.'
            );
        }

        try {
            $metadata = json_decode(
                $metadataJson,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Stored report design metadata is invalid.',
                previous: $exception
            );
        }

        if (!is_array($metadata)) {
            throw new \RuntimeException(
                'Stored report design metadata is invalid.'
            );
        }

        $expectedHtmlHash = $metadata['html_sha256'] ?? null;
        $expectedCssHash = $metadata['css_sha256'] ?? null;

        if (
            !is_string($expectedHtmlHash)
            || !hash_equals(
                $expectedHtmlHash,
                hash('sha256', $html)
            )
            || !is_string($expectedCssHash)
            || !hash_equals(
                $expectedCssHash,
                hash('sha256', $css)
            )
        ) {
            throw new \RuntimeException(
                'Stored report design integrity check failed.'
            );
        }

        return [
            'html' => $html,
            'css' => $css,
            'metadata' => $metadata,
        ];
    }

    private function stageDirectory(
        ReportDesignDefinition $definition,
        string $stage,
    ): string {
        return rtrim($this->storageDirectory, '/')
            .'/'.$definition->document
            .'/'.$definition->variant
            .'/'.$stage;
    }

    private function assertStage(string $stage): void
    {
        if (!in_array($stage, ['draft', 'approved'], true)) {
            throw new \InvalidArgumentException(
                'Invalid report design stage.'
            );
        }
    }

    private function assertContent(string $html, string $css): void
    {
        if (trim($html) === '') {
            throw new \InvalidArgumentException(
                'Report design HTML cannot be empty.'
            );
        }

        if (
            strlen($html) > self::MAX_HTML_BYTES
            || strlen($css) > self::MAX_CSS_BYTES
        ) {
            throw new \InvalidArgumentException(
                'Report design exceeds its size limit.'
            );
        }

        if (
            str_contains($html, "\0")
            || str_contains($css, "\0")
        ) {
            throw new \InvalidArgumentException(
                'Report design contains invalid bytes.'
            );
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Report design directory could not be created.'
            );
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(
                'Report design directory is not writable.'
            );
        }
    }

    private function writeAtomically(
        string $path,
        string $contents,
    ): void {
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $temporary = tempnam(
            $directory,
            '.report-design-'
        );

        if ($temporary === false) {
            throw new \RuntimeException(
                'Temporary report design file could not be created.'
            );
        }

        try {
            if (
                file_put_contents(
                    $temporary,
                    $contents,
                    LOCK_EX
                ) === false
            ) {
                throw new \RuntimeException(
                    'Report design file could not be written.'
                );
            }

            @chmod($temporary, 0600);

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(
                    'Report design file could not be activated.'
                );
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
