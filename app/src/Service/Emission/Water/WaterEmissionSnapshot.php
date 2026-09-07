<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Entity\EmissionRecord;

final class WaterEmissionSnapshot
{
    public const VERSION = 'water-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(WaterEmissionInput $input, WaterEmissionResult $result, array $presentation = []): string
    {
        return json_encode([
            'version' => self::VERSION,
            'calculatorVersion' => self::VERSION,
            'input' => $this->inputToArray($input),
            'calculation' => $result->toArray(),
            'presentation' => $presentation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string|null> */
    public function inputToArray(WaterEmissionInput $input): array
    {
        return [
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'waterUseType' => $input->waterUseType,
            'volumeInput' => $input->volumeInput,
            'volumeInputUnit' => $input->volumeInputUnit,
            'destination' => $input->destination,
        ];
    }

    public function decodeInput(string $snapshot): WaterEmissionInput
    {
        $input = $this->snapshotSection($snapshot, 'input');

        return new WaterEmissionInput(
            startDate: $this->optionalDate($input, 'startDate'),
            endDate: $this->optionalDate($input, 'endDate'),
            country: $this->optionalString($input, 'country'),
            waterUseType: $this->optionalString($input, 'waterUseType'),
            volumeInput: $this->optionalString($input, 'volumeInput'),
            volumeInputUnit: $this->optionalString($input, 'volumeInputUnit'),
            destination: $this->optionalString($input, 'destination'),
        );
    }

    /** @return array<string, scalar|null> */
    public function decodePresentation(string $snapshot): array
    {
        $presentation = $this->snapshotSection($snapshot, 'presentation');
        foreach ($presentation as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                throw new \UnexpectedValueException('Invalid water emission snapshot presentation.');
            }
        }

        return $presentation;
    }

    public function isWaterV1Record(EmissionRecord $record, int $waterCategoryId): bool
    {
        if (null !== $record->getActivity() || $waterCategoryId !== $record->getEffectiveCategory()?->getId()) {
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
            throw new \UnexpectedValueException('Unsupported water emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshotSection(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid water emission snapshot section: %s.', $section));
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
            throw new \UnexpectedValueException(sprintf('Invalid water emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        if (!array_key_exists($field, $input)) {
            throw new \UnexpectedValueException(sprintf('Missing water emission snapshot input: %s.', $field));
        }

        $value = $input[$field];
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid water emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
