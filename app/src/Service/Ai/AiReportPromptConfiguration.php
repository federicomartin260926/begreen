<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiReportPromptConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class AiReportPromptConfiguration
{
    private string $version;

    private string $technicalInstructions;

    /** @var array{general:string, executive_summary:string, category:string, avoid:string, final_conclusion:string} */
    private array $editorialDefaults;

    public function __construct(string $promptFile)
    {
        if (!is_file($promptFile) || !is_readable($promptFile)) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration file is unavailable.');
        }

        try {
            $configuration = Yaml::parseFile($promptFile);
        } catch (ParseException) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        if (!is_array($configuration)) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        $version = $configuration['version'] ?? null;
        $technicalSections = $configuration['technical_instructions'] ?? null;
        $editorialSections = $configuration['editorial_defaults'] ?? null;
        if (
            !is_string($version)
            || trim($version) === ''
            || !is_array($technicalSections)
            || $technicalSections === []
            || !is_array($editorialSections)
        ) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        $requiredEditorialSections = ['general', 'executive_summary', 'category', 'avoid', 'final_conclusion'];
        $editorialKeys = array_keys($editorialSections);
        sort($editorialKeys);
        $expectedEditorialKeys = $requiredEditorialSections;
        sort($expectedEditorialKeys);
        if ($editorialKeys !== $expectedEditorialKeys) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        $this->version = trim($version);
        $this->technicalInstructions = $this->parseSections($technicalSections);
        $editorialDefaults = [];
        foreach ($requiredEditorialSections as $section) {
            $editorialDefaults[$section] = $this->parseInstructionList($editorialSections[$section] ?? null);
        }
        $this->editorialDefaults = $editorialDefaults;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function technicalInstructions(): string
    {
        return $this->technicalInstructions;
    }

    /** @return array{general:string, executive_summary:string, category:string, avoid:string, final_conclusion:string} */
    public function editorialDefaults(): array
    {
        return $this->editorialDefaults;
    }

    /** @param array<mixed> $sections */
    private function parseSections(array $sections): string
    {
        $instructionGroups = [];
        foreach ($sections as $section) {
            $instructionGroups[] = $this->parseInstructionList($section);
        }

        return implode("\n\n", $instructionGroups);
    }

    private function parseInstructionList(mixed $section): string
    {
        if (!is_array($section) || !array_is_list($section) || $section === []) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        $instructions = [];
        foreach ($section as $instruction) {
            if (!is_string($instruction) || trim($instruction) === '') {
                throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
            }

            $instructions[] = trim($instruction);
        }

        return implode("\n", $instructions);
    }
}
