<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosEmissionEntryContext
{
    /** @param array<string, string|null> $formDefaults */
    public function __construct(
        public \DateTimeImmutable $date,
        public string $view,
        public string $categoryKey,
        public string $subcategoryKey,
        private array $formDefaults,
    ) {
    }

    /** @return array<string, string|null> */
    public function formDefaults(): array
    {
        return $this->formDefaults;
    }

    /** @return array{view: string, date: string} */
    public function returnQuery(): array
    {
        return [
            'view' => $this->view,
            'date' => $this->date->format('Y-m-d'),
        ];
    }
}
