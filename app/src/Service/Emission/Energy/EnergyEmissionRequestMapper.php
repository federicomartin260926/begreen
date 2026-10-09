<?php

namespace App\Service\Emission\Energy;

use App\Service\Emission\EmissionCountryCatalog;
use Symfony\Component\HttpFoundation\Request;

final class EnergyEmissionRequestMapper
{
    private readonly EmissionCountryCatalog $countryCatalog;
    private readonly EnergyEquipmentCatalog $equipmentCatalog;

    public function __construct(
        ?EmissionCountryCatalog $countryCatalog = null,
        ?EnergyEquipmentCatalog $equipmentCatalog = null,
    ) {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
        $this->equipmentCatalog = $equipmentCatalog ?? new EnergyEquipmentCatalog();
    }

    public function map(Request $request): EnergyEmissionInput
    {
        $family = $this->requiredString($request, 'family');
        if (!in_array($family, [
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            EnergyEmissionInput::FAMILY_EQUIPMENT,
            EnergyEmissionInput::FAMILY_BATTERY,
            EnergyEmissionInput::FAMILY_DIGITAL,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported energy family.');
        }
        $country = $this->country($request, 'country');
        $isSpain = 'ES' === $country;
        $origin = $this->optionalString($request, 'origin');
        $supplier = null;
        $labeling = null;
        $renewableCertificate = null;
        $certifiedKwh = null;
        $equipmentType = $this->optionalString($request, 'equipmentType');
        $fuel = $this->optionalString($request, 'fuel');
        $mode = $this->optionalString($request, 'mode') ?? '';
        if (EnergyEmissionInput::FAMILY_ELECTRICITY === $family) {
            if ($isSpain && in_array($origin, [EnergyEmissionInput::ORIGIN_GRID, EnergyEmissionInput::ORIGIN_MIXED], true)) {
                $supplierKnown = $this->optionalString($request, 'electricitySupplierKnown');
                if (!in_array($supplierKnown, ['yes', 'no'], true)) {
                    throw new \InvalidArgumentException('electricitySupplierKnown must be yes or no.');
                }
                if ('yes' === $supplierKnown) {
                    $supplier = $this->requiredString($request, 'electricitySupplier');
                }
            } elseif (!$isSpain) {
                $supplier = $this->familyString($request, $family, 'Supplier');
                if (in_array($origin, [EnergyEmissionInput::ORIGIN_GRID, EnergyEmissionInput::ORIGIN_MIXED, EnergyEmissionInput::ORIGIN_UNKNOWN], true)) {
                    $renewableCertificate = $this->optionalString($request, 'renewableCertificate');
                    if (null !== $renewableCertificate && !in_array($renewableCertificate, ['yes', 'no', 'unknown'], true)) {
                        throw new \InvalidArgumentException('renewableCertificate must be yes, no or unknown.');
                    }
                    if ('yes' === $renewableCertificate) {
                        $certifiedKwh = $this->optionalString($request, 'certifiedKwh');
                        if (null !== $certifiedKwh && !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $certifiedKwh)) {
                            throw new \InvalidArgumentException('certifiedKwh must be a non-negative decimal.');
                        }
                    }
                }
            }
        } elseif (EnergyEmissionInput::FAMILY_BATTERY === $family) {
            $supplier = $isSpain ? $this->familyString($request, $family, 'Supplier') : null;
            $labeling = $isSpain ? null : $this->familyString($request, $family, 'Labeling');
        } elseif (EnergyEmissionInput::FAMILY_EQUIPMENT === $family
            && null !== $equipmentType && '' !== $equipmentType
            && null !== $fuel && '' !== $fuel) {
            if (!$this->equipmentCatalog->isAllowed($equipmentType, $fuel, $country)) {
                throw new \InvalidArgumentException('Fuel is not compatible with the selected equipment.');
            }
            $bottledGas = in_array($fuel, ['Gas butano', 'Gas propano'], true);
            if (!$bottledGas) {
                if (!in_array($mode, ['', EnergyEmissionInput::EQUIPMENT_MODE_DIRECT], true)) {
                    throw new \InvalidArgumentException('Cylinder mode is only available for butane and propane.');
                }
                $mode = EnergyEmissionInput::EQUIPMENT_MODE_DIRECT;
            }
        }

        return new EnergyEmissionInput(
            family: $family,
            startDate: $this->date($request, 'startDate'),
            endDate: $this->date($request, 'endDate'),
            country: $country,
            origin: $origin,
            amount: $this->optionalString($request, 'amount'),
            unit: $this->optionalString($request, 'unit'),
            initialReading: $this->optionalString($request, 'initialReading'),
            finalReading: $this->optionalString($request, 'finalReading'),
            gridKwh: $this->optionalString($request, 'gridKwh'),
            solarKwh: $this->optionalString($request, 'solarKwh'),
            supplier: $supplier,
            labeling: $labeling,
            renewableCertificate: $renewableCertificate,
            certifiedKwh: $certifiedKwh,
            equipmentType: $equipmentType,
            fuel: $fuel,
            mode: $mode,
            bottleSizeKg: $this->optionalString($request, 'bottleSizeKg'),
            bottleCount: $this->optionalString($request, 'bottleCount'),
            batteryType: $this->optionalString($request, 'batteryType'),
            chargeSource: $this->optionalString($request, 'chargeSource'),
            chargedKwh: $this->optionalString($request, 'chargedKwh'),
            digitalType: $this->optionalString($request, 'digitalType'),
            digitalLocation: $this->optionalString($request, 'digitalLocation'),
            digitalCountry: $this->optionalCountry($request, 'digitalCountry'),
            knownKwh: $this->optionalString($request, 'knownKwh'),
            hours: $this->optionalString($request, 'hours'),
            units: $this->optionalString($request, 'units'),
            gpu: $this->optionalString($request, 'gpu'),
            service: $this->optionalString($request, 'service'),
            model: $this->optionalString($request, 'model'),
            provider: $this->optionalString($request, 'provider'),
            ownership: $this->optionalString($request, 'ownership'),
        );
    }

    private function familyString(Request $request, string $family, string $suffix): ?string
    {
        if (!in_array($family, [EnergyEmissionInput::FAMILY_ELECTRICITY, EnergyEmissionInput::FAMILY_BATTERY], true)) {
            return null;
        }

        return $this->optionalString($request, $family.$suffix);
    }

    private function optionalCountry(Request $request, string $field): ?string
    {
        $value = $this->optionalString($request, $field);

        return null === $value ? null : $this->countryCatalog->iso2FromIso3($value);
    }

    private function date(Request $request, string $field): \DateTimeImmutable
    {
        $value = $this->requiredString($request, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid YYYY-MM-DD date.', $field));
        }

        return $date;
    }

    private function country(Request $request, string $field): string
    {
        return $this->countryCatalog->iso2FromIso3($this->requiredString($request, $field));
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $this->optionalString($request, $field);
        if (null === $value) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return $value;
    }

    private function optionalString(Request $request, string $field): ?string
    {
        $value = $request->request->get($field);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
