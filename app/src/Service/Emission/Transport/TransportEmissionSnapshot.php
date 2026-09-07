<?php

namespace App\Service\Emission\Transport;

use App\Entity\EmissionRecord;

final class TransportEmissionSnapshot
{
    public const VERSION = 'transport-v20';

    /** @param array<string, string> $presentation */
    public function encode(TransportEmissionInput $input, TransportEmissionResult $result, array $presentation = []): string
    {
        $snapshot = [
            'version' => self::VERSION,
            'input' => $this->inputToArray($input),
            'calculation' => [
                'status' => $result->status,
                'normalizedActivityValue' => $result->normalizedActivityValue,
                'normalizedActivityUnit' => $result->normalizedActivityUnit,
                'generatedKgCo2e' => $result->generatedKgCo2e,
            ],
            'factor' => [
                'functionalKey' => $result->functionalKey,
                'activityYear' => $result->activityYear,
                'factorYear' => $result->factorYear,
                'value' => $result->factorValue,
                'unit' => $result->factorUnit,
                'source' => $result->source,
                'sourceDetail' => $result->sourceDetail,
                'fallback' => $result->isFallback,
                'fallbackReason' => $result->fallbackReason,
            ],
        ];
        if ([] !== $presentation) {
            $snapshot['presentation'] = $presentation;
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, string|null> */
    public function inputToArray(TransportEmissionInput $input): array
    {
        return [
            'category' => $input->category,
            'mode' => $input->mode,
            'method' => $input->method,
            'country' => $input->country,
            'startedAt' => $input->startedAt->format('Y-m-d'),
            'activityValue' => $input->activityValue,
            'activityUnit' => $input->activityUnit,
            'repetitions' => $input->repetitions,
            'passengers' => $input->passengers,
            'weightValue' => $input->weightValue,
            'weightUnit' => $input->weightUnit,
            'vehicleType' => $input->vehicleType,
            'carSize' => $input->carSize,
            'fuel' => $input->fuel,
            'thermalFuel' => $input->thermalFuel,
            'routeClassification' => $input->routeClassification,
            'travelClass' => $input->travelClass,
        ];
    }

    public function decode(string $snapshot): TransportEmissionInput
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || self::VERSION !== ($data['version'] ?? null) || !is_array($data['input'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported transport emission snapshot version.');
        }

        $input = $data['input'];
        $required = ['category', 'mode', 'method', 'country', 'startedAt', 'activityValue', 'activityUnit', 'repetitions'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                throw new \UnexpectedValueException(sprintf('Invalid transport emission snapshot input: %s.', $field));
            }
        }

        $startedAt = \DateTimeImmutable::createFromFormat('!Y-m-d', $input['startedAt']);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$startedAt || (is_array($dateErrors) && (0 !== $dateErrors['warning_count'] || 0 !== $dateErrors['error_count']))) {
            throw new \UnexpectedValueException('Invalid transport emission snapshot date.');
        }

        return new TransportEmissionInput(
            $input['category'],
            $input['mode'],
            $input['method'],
            $input['country'],
            $startedAt,
            $input['activityValue'],
            $input['activityUnit'],
            $input['repetitions'],
            $this->optionalString($input, 'passengers'),
            $this->optionalString($input, 'weightValue'),
            $this->optionalString($input, 'weightUnit'),
            $this->optionalString($input, 'vehicleType'),
            $this->optionalString($input, 'carSize'),
            $this->optionalString($input, 'fuel'),
            $this->optionalString($input, 'thermalFuel'),
            $this->optionalString($input, 'routeClassification'),
            $this->optionalString($input, 'travelClass'),
        );
    }

    /** @return array<string, string> */
    public function decodePresentation(string $snapshot): array
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        $presentation = $data['presentation'] ?? [];
        if (!is_array($presentation)) {
            throw new \UnexpectedValueException('Invalid transport emission presentation.');
        }

        foreach ($presentation as $field => $value) {
            if (!is_string($field) || !is_string($value)) {
                throw new \UnexpectedValueException('Invalid transport emission presentation value.');
            }
        }

        return $presentation;
    }

    /**
     * @return array{
     *     mode: string,
     *     detailKind: ?string,
     *     detailCode: ?string,
     *     normalizedActivityUnit: ?string,
     *     displayActivityUnit: ?string
     * }
     */
    public function decodeSummary(string $snapshot): array
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || self::VERSION !== ($data['version'] ?? null)
            || !is_array($data['input'] ?? null)
            || !is_array($data['calculation'] ?? null)
        ) {
            throw new \UnexpectedValueException('Invalid transport emission snapshot summary.');
        }

        $mode = $data['input']['mode'] ?? null;
        if (!is_string($mode) || '' === $mode) {
            throw new \UnexpectedValueException('Invalid transport emission snapshot mode.');
        }

        $vehicleType = $data['input']['vehicleType'] ?? null;
        $fuel = $data['input']['fuel'] ?? null;
        $unit = $data['calculation']['normalizedActivityUnit'] ?? null;

        foreach (['vehicleType' => $vehicleType, 'fuel' => $fuel, 'normalizedActivityUnit' => $unit] as $field => $value) {
            if (null !== $value && !is_string($value)) {
                throw new \UnexpectedValueException(sprintf('Invalid transport emission snapshot summary: %s.', $field));
            }
        }

        $detailKind = null;
        $detailCode = null;

        if (is_string($vehicleType) && '' !== $vehicleType) {
            $detailKind = 'vehicle_type';
            $detailCode = $vehicleType;
        } elseif (is_string($fuel) && '' !== $fuel) {
            $detailKind = 'fuel';
            $detailCode = $fuel;
        }

        $displayUnit = match ($unit) {
            'km*pasajero' => 'passenger-km',
            'km*tonelada' => 't-km',
            'litros' => 'L',
            default => $unit,
        };

        return [
            'mode' => $mode,
            'detailKind' => $detailKind,
            'detailCode' => $detailCode,
            'normalizedActivityUnit' => $unit,
            'displayActivityUnit' => $displayUnit,
        ];
    }

    public function isTransportV20(?string $snapshot): bool
    {
        if (null === $snapshot) {
            return false;
        }

        try {
            $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($data) && self::VERSION === ($data['version'] ?? null);
    }

    public function isTransportV20Record(EmissionRecord $record, int $transportCategoryId): bool
    {
        return null === $record->getActivity()
            && $transportCategoryId === $record->getEffectiveCategory()?->getId()
            && $this->isTransportV20($record->getCalculationDetails());
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid transport emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
