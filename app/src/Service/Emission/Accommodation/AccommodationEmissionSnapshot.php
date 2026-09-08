<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Entity\EmissionRecord;

final class AccommodationEmissionSnapshot
{
    public const VERSION = 'accommodation-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(
        AccommodationEmissionInput $input,
        AccommodationEmissionResult $result,
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
    public function inputToArray(AccommodationEmissionInput $input): array
    {
        return [
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'iso3' => $input->iso3,
            'accommodationType' => $input->accommodationType,
            'stars' => $input->stars,
            'occupiedRooms' => $input->occupiedRooms,
            'nights' => $input->nights,
            'people' => $input->people,
        ];
    }

    public function decodeInput(string $snapshot): AccommodationEmissionInput
    {
        $input = $this->snapshotSection($snapshot, 'input');

        return new AccommodationEmissionInput(
            $this->optionalDate($input, 'startDate'),
            $this->optionalDate($input, 'endDate'),
            $this->optionalString($input, 'iso3'),
            $this->optionalString($input, 'accommodationType'),
            $this->optionalString($input, 'stars'),
            $this->optionalString($input, 'occupiedRooms'),
            $this->optionalString($input, 'nights'),
            $this->optionalString($input, 'people'),
        );
    }

    /** @return array<string, scalar|null> */
    public function decodePresentation(string $snapshot): array
    {
        $presentation = $this->snapshotSection($snapshot, 'presentation');
        foreach ($presentation as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                throw new \UnexpectedValueException('Invalid accommodation emission snapshot presentation.');
            }
        }

        return $presentation;
    }

    public function isAccommodationV1Record(EmissionRecord $record, int $categoryId): bool
    {
        if (null !== $record->getActivity() || $categoryId !== $record->getEffectiveCategory()?->getId()) {
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
            throw new \UnexpectedValueException('Unsupported accommodation emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshotSection(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid accommodation emission snapshot section: %s.', $section));
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
            throw new \UnexpectedValueException(sprintf('Invalid accommodation emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        if (!array_key_exists($field, $input)) {
            throw new \UnexpectedValueException(sprintf('Missing accommodation emission snapshot input: %s.', $field));
        }
        $value = $input[$field];
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid accommodation emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
