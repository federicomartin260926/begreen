<?php

declare(strict_types=1);

namespace App\Service\ReportDesign;

final readonly class ReportDesignDefinition
{
    public function __construct(
        public string $document,
        public string $variant,
        public string $label,
    ) {
        foreach ([$document, $variant] as $key) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $key)) {
                throw new \InvalidArgumentException('Invalid report design key.');
            }
        }
    }

    public function key(): string
    {
        return $this->document.'/'.$this->variant;
    }
}
