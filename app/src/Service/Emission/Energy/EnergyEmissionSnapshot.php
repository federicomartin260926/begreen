<?php

declare(strict_types=1);

namespace App\Service\Emission\Energy;

final class EnergyEmissionSnapshot
{
    public const VERSION = 'energy-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(EnergyEmissionInput $input, EnergyEmissionResult $result, array $presentation = []): string
    {
        return json_encode([
            'version' => self::VERSION,
            'calculatorVersion' => self::VERSION,
            'family' => $input->family,
            'input' => $this->inputToArray($input),
            'calculation' => $result->toArray(),
            'presentation' => $presentation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function inputToArray(EnergyEmissionInput $input): array
    {
        return [
            'family' => $input->family,
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'origin' => $input->origin,
            'amount' => $input->amount,
            'unit' => $input->unit,
            'initialReading' => $input->initialReading,
            'finalReading' => $input->finalReading,
            'gridKwh' => $input->gridKwh,
            'solarKwh' => $input->solarKwh,
            'supplier' => $input->supplier,
            'labeling' => $input->labeling,
            'equipmentType' => $input->equipmentType,
            'fuel' => $input->fuel,
            'mode' => $input->mode,
            'bottleSizeKg' => $input->bottleSizeKg,
            'bottleCount' => $input->bottleCount,
            'batteryType' => $input->batteryType,
            'chargeSource' => $input->chargeSource,
            'chargedKwh' => $input->chargedKwh,
            'digitalType' => $input->digitalType,
            'digitalLocation' => $input->digitalLocation,
            'digitalCountry' => $input->digitalCountry,
            'knownKwh' => $input->knownKwh,
            'hours' => $input->hours,
            'units' => $input->units,
            'gpu' => $input->gpu,
            'service' => $input->service,
            'model' => $input->model,
            'provider' => $input->provider,
            'ownership' => $input->ownership,
        ];
    }

    public function decodeInput(string $snapshot): EnergyEmissionInput
    {
        $input = $this->snapshotSection($snapshot, 'input');
        $family = $this->requiredString($input, 'family');
        if (!in_array($family, [
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            EnergyEmissionInput::FAMILY_EQUIPMENT,
            EnergyEmissionInput::FAMILY_BATTERY,
            EnergyEmissionInput::FAMILY_DIGITAL,
        ], true)) {
            throw new \UnexpectedValueException('Invalid energy emission snapshot family.');
        }

        return new EnergyEmissionInput(
            family: $family,
            startDate: $this->requiredDate($input, 'startDate'),
            endDate: $this->requiredDate($input, 'endDate'),
            country: $this->requiredString($input, 'country'),
            origin: $this->optionalString($input, 'origin'),
            amount: $this->optionalString($input, 'amount'),
            unit: $this->optionalString($input, 'unit'),
            initialReading: $this->optionalString($input, 'initialReading'),
            finalReading: $this->optionalString($input, 'finalReading'),
            gridKwh: $this->optionalString($input, 'gridKwh'),
            solarKwh: $this->optionalString($input, 'solarKwh'),
            supplier: $this->optionalString($input, 'supplier'),
            labeling: $this->optionalString($input, 'labeling'),
            equipmentType: $this->optionalString($input, 'equipmentType'),
            fuel: $this->optionalString($input, 'fuel'),
            mode: $this->optionalString($input, 'mode') ?? EnergyEmissionInput::EQUIPMENT_MODE_DIRECT,
            bottleSizeKg: $this->optionalString($input, 'bottleSizeKg'),
            bottleCount: $this->optionalString($input, 'bottleCount'),
            batteryType: $this->optionalString($input, 'batteryType'),
            chargeSource: $this->optionalString($input, 'chargeSource'),
            chargedKwh: $this->optionalString($input, 'chargedKwh'),
            digitalType: $this->optionalString($input, 'digitalType'),
            digitalLocation: $this->optionalString($input, 'digitalLocation'),
            digitalCountry: $this->optionalString($input, 'digitalCountry'),
            knownKwh: $this->optionalString($input, 'knownKwh'),
            hours: $this->optionalString($input, 'hours'),
            units: $this->optionalString($input, 'units'),
            gpu: $this->optionalString($input, 'gpu'),
            service: $this->optionalString($input, 'service'),
            model: $this->optionalString($input, 'model'),
            provider: $this->optionalString($input, 'provider'),
            ownership: $this->optionalString($input, 'ownership'),
        );
    }

    /** @return array<string, scalar|null> */
    public function decodePresentation(string $snapshot): array
    {
        $presentation = $this->snapshotSection($snapshot, 'presentation');
        foreach ($presentation as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                throw new \UnexpectedValueException('Invalid energy emission snapshot presentation.');
            }
        }

        return $presentation;
    }

    /** @return array{family: string, normalizedUnit: ?string} */
    public function decodeSummary(string $snapshot): array
    {
        $data = $this->decode($snapshot);
        $family = $data['family'] ?? null;
        $unit = $data['calculation']['normalizedUnit'] ?? null;
        if (!is_string($family) || (null !== $unit && !is_string($unit))) {
            throw new \UnexpectedValueException('Invalid energy emission snapshot summary.');
        }

        return ['family' => $family, 'normalizedUnit' => $unit];
    }

    public function isEnergyV1Record(\App\Entity\EmissionRecord $record, int $energyCategoryId): bool
    {
        if (null !== $record->getActivity() || $energyCategoryId !== $record->getEffectiveCategory()?->getId()) {
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
            throw new \UnexpectedValueException('Unsupported energy emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshotSection(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid energy emission snapshot section: %s.', $section));
        }

        return $data[$section];
    }

    /** @param array<string, mixed> $input */
    private function requiredDate(array $input, string $field): \DateTimeImmutable
    {
        $value = $this->requiredString($input, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \UnexpectedValueException(sprintf('Invalid energy emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = $this->optionalString($input, $field);
        if (null === $value) {
            throw new \UnexpectedValueException(sprintf('Invalid energy emission snapshot input: %s.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid energy emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
