<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Entity\EmissionRecord;

final class WasteEmissionSnapshot
{
    public const VERSION = 'waste-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(
        WasteEmissionInput $input,
        WasteEmissionResult $result,
        array $presentation = [],
    ): string {
        return json_encode([
            'version' => self::VERSION,
            'calculatorVersion' => self::VERSION,
            'input' => $this->inputToArray($input),
            'calculation' => $result->toArray(),
            'presentation' => $presentation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string|null> */
    public function inputToArray(WasteEmissionInput $input): array
    {
        return [
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'wasteType' => $input->wasteType,
            'wasteActivity' => $input->wasteActivity,
            'treatment' => $input->treatment,
            'weight' => $input->weight,
            'weightUnit' => $input->weightUnit,
        ];
    }

    public function decodeInput(string $snapshot): WasteEmissionInput
    {
        $input = $this->snapshotSection($snapshot, 'input');

        return new WasteEmissionInput(
            $this->optionalDate($input, 'startDate'),
            $this->optionalDate($input, 'endDate'),
            $this->optionalString($input, 'country'),
            $this->optionalString($input, 'wasteType'),
            $this->optionalString($input, 'wasteActivity'),
            $this->optionalString($input, 'treatment'),
            $this->optionalString($input, 'weight'),
            $this->optionalString($input, 'weightUnit'),
        );
    }

    /** @return array<string, scalar|null> */
    public function decodePresentation(string $snapshot): array
    {
        $presentation = $this->snapshotSection($snapshot, 'presentation');
        foreach ($presentation as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                throw new \UnexpectedValueException('Invalid waste emission snapshot presentation.');
            }
        }

        return $presentation;
    }

    public function isWasteV1Record(EmissionRecord $record, int $categoryId): bool
    {
        if ($categoryId !== $record->getEffectiveCategory()?->getId()) {
            return false;
        }

        try {
            $this->decode((string) $record->getCalculationDetails());
        } catch (\JsonException|\UnexpectedValueException) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function decode(string $snapshot): array
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || self::VERSION !== ($data['version'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported waste emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshotSection(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid waste emission snapshot section: %s.', $section));
        }

        return $data[$section];
    }

    /** @param array<string, mixed> $input */
    private function optionalDate(array $input, string $field): ?\DateTimeImmutable
    {
        $value = $this->optionalString($input, $field);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \UnexpectedValueException(sprintf('Invalid waste emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        if (!array_key_exists($field, $input)) {
            throw new \UnexpectedValueException(sprintf('Missing waste emission snapshot input: %s.', $field));
        }
        $value = $input[$field];
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid waste emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
