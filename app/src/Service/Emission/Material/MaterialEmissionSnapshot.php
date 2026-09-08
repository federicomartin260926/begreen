<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionRecord;

final class MaterialEmissionSnapshot
{
    public const VERSION = 'material-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(MaterialEmissionInput $input, MaterialEmissionResult $result, array $presentation = []): string
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
    public function inputToArray(MaterialEmissionInput $input): array
    {
        return [
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'activity' => $input->activity,
            'subproduct' => $input->subproduct,
            'origin' => $input->origin,
            'measurementMethod' => $input->measurementMethod,
            'inputQuantity' => $input->inputQuantity,
            'inputUnit' => $input->inputUnit,
            'woodType' => $input->woodType,
            'boardFamily' => $input->boardFamily,
            'boardThickness' => $input->boardThickness,
            'lengthMeters' => $input->lengthMeters,
            'widthMeters' => $input->widthMeters,
            'thicknessMeters' => $input->thicknessMeters,
            'unitCount' => $input->unitCount,
            'pieceWeightKg' => $input->pieceWeightKg,
            'grammageGm2' => $input->grammageGm2,
            'paperFormat' => $input->paperFormat,
            'sheetsPerPackage' => $input->sheetsPerPackage,
            'cardboardType' => $input->cardboardType,
            'batteryChemistry' => $input->batteryChemistry,
            'batterySize' => $input->batterySize,
            'family' => $input->family,
        ];
    }

    public function decodeInput(string $snapshot): MaterialEmissionInput
    {
        $input = $this->section($snapshot, 'input');

        return new MaterialEmissionInput(
            startDate: $this->optionalDate($input, 'startDate'),
            endDate: $this->optionalDate($input, 'endDate'),
            country: $this->optionalString($input, 'country'),
            activity: $this->optionalString($input, 'activity'),
            subproduct: $this->optionalString($input, 'subproduct'),
            origin: $this->optionalString($input, 'origin'),
            measurementMethod: $this->optionalString($input, 'measurementMethod'),
            inputQuantity: $this->optionalString($input, 'inputQuantity'),
            inputUnit: $this->optionalString($input, 'inputUnit'),
            woodType: $this->optionalString($input, 'woodType'),
            boardFamily: $this->optionalString($input, 'boardFamily'),
            boardThickness: $this->optionalString($input, 'boardThickness'),
            lengthMeters: $this->optionalString($input, 'lengthMeters'),
            widthMeters: $this->optionalString($input, 'widthMeters'),
            thicknessMeters: $this->optionalString($input, 'thicknessMeters'),
            unitCount: $this->optionalString($input, 'unitCount'),
            pieceWeightKg: $this->optionalString($input, 'pieceWeightKg'),
            grammageGm2: $this->optionalString($input, 'grammageGm2'),
            paperFormat: $this->optionalString($input, 'paperFormat'),
            sheetsPerPackage: $this->optionalString($input, 'sheetsPerPackage'),
            cardboardType: $this->optionalString($input, 'cardboardType'),
            batteryChemistry: $this->optionalString($input, 'batteryChemistry'),
            batterySize: $this->optionalString($input, 'batterySize'),
            family: $this->optionalString($input, 'family'),
        );
    }

    /** @return array<string, mixed> */
    public function decodeCalculation(string $snapshot): array
    {
        return $this->section($snapshot, 'calculation');
    }

    public function isMaterialV1Record(EmissionRecord $record, int $categoryId): bool
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
            throw new \UnexpectedValueException('Unsupported material emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function section(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid material emission snapshot section: %s.', $section));
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
            throw new \UnexpectedValueException(sprintf('Invalid material emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        if (!array_key_exists($field, $input)) {
            throw new \UnexpectedValueException(sprintf('Missing material emission snapshot input: %s.', $field));
        }
        $value = $input[$field];
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid material emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
