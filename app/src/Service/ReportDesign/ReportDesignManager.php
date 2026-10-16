<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final readonly class ReportDesignManager
{
    public function __construct(
        private ReportDesignStorage $storage,
        private ReportDesignValidator $validator,
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
        $this->validator->validate($html, $css, $definition);

        return $this->storage->saveDraft(
            $definition,
            $html,
            $css,
            $metadata,
        );
    }

    /**
     * @return array{source:string,html:string,css:string}
     */
    public function startingPoint(
        ReportDesignDefinition $definition,
    ): array {
        $draft = $this->storage->loadDraft($definition);

        if ($draft !== null) {
            return [
                'source' => 'draft',
                'html' => $draft['html'],
                'css' => $draft['css'],
            ];
        }

        $approved = $this->storage->loadApproved($definition);

        if ($approved !== null) {
            return [
                'source' => 'approved',
                'html' => $approved['html'],
                'css' => $approved['css'],
            ];
        }

        return [
            'source' => 'base',
            'html' => '',
            'css' => '',
        ];
    }

    public function discardDraft(
        ReportDesignDefinition $definition,
    ): void {
        $this->storage->discardDraft($definition);
    }

    /**
     * @return array{html:string,css:string,metadata:array<string,mixed>}
     */
    public function approve(
        ReportDesignDefinition $definition,
    ): array {
        $draft = $this->storage->loadDraft($definition);

        if ($draft === null) {
            throw new \RuntimeException(
                'There is no draft report design to approve.'
            );
        }

        $this->validator->validate(
            $draft['html'],
            $draft['css'],
            $definition,
        );

        return $this->storage->approve($definition);
    }
}
