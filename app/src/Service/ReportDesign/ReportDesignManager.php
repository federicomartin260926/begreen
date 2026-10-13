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
        $this->validator->validate($html, $css);

        return $this->storage->saveDraft(
            $definition,
            $html,
            $css,
            $metadata,
        );
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
        );

        return $this->storage->approve($definition);
    }
}
