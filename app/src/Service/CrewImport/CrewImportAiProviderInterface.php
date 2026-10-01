<?php

namespace App\Service\CrewImport;

interface CrewImportAiProviderInterface
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function request(string $instructions, string $context, array $schema): array;
}
