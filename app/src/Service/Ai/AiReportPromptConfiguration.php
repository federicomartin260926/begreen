<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiReportPromptConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class AiReportPromptConfiguration
{
    private string $version;

    private string $instructions;

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
        $sections = $configuration['instructions'] ?? null;
        if (!is_string($version) || trim($version) === '' || !is_array($sections) || $sections === []) {
            throw new AiReportPromptConfigurationException('The AI report prompt configuration is invalid.');
        }

        $instructionGroups = [];
        foreach ($sections as $section) {
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

            $instructionGroups[] = implode("\n", $instructions);
        }

        $this->version = trim($version);
        $this->instructions = implode("\n\n", $instructionGroups);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }
}
