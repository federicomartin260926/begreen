<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionRecord;

final readonly class MaterialAmountNormalizer
{
    private const SCALE = 18;

    public function __construct(private MaterialUiCatalog $catalog)
    {
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    public function normalize(MaterialEmissionInput $input): array
    {
        $activity = (string) $input->activity;
        if ($this->catalog->isPlastic($activity) && 'Reutilizado' === $input->origin) {
            return $this->unavailable('automatic_factor_unavailable', 'kg');
        }

        return match ($input->measurementMethod) {
            MaterialEmissionInput::METHOD_WEIGHT => $this->weight($input),
            MaterialEmissionInput::METHOD_DIMENSIONS => $this->dimensions($input),
            MaterialEmissionInput::METHOD_PACKAGES => $this->paperPackages($input),
            MaterialEmissionInput::METHOD_GRAMMAGE => $this->grammage($input),
            MaterialEmissionInput::METHOD_UNITS => $this->units($input),
            MaterialEmissionInput::METHOD_SURFACE => $this->surface($input),
            MaterialEmissionInput::METHOD_VOLUME => $this->volume($input),
            default => $this->pending('measurement_method_required'),
        };
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function weight(MaterialEmissionInput $input): array
    {
        if (MaterialUiCatalog::ACTIVITY_CARPET === $input->activity) {
            return $this->unavailable('carpet_weight_conversion_unavailable', 'm²');
        }
        if (MaterialUiCatalog::ACTIVITY_SOLVENT === $input->activity) {
            return $this->unavailable('solvent_mass_conversion_unavailable', 'l');
        }
        if ('kg' !== $input->inputUnit) {
            return $this->pending('weight_kg_required');
        }

        return $this->positiveResult($input->inputQuantity, 'kg', 'weight_invalid');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function dimensions(MaterialEmissionInput $input): array
    {
        if (MaterialUiCatalog::ACTIVITY_WOOD === $input->activity) {
            return $this->woodDimensions($input);
        }
        if (MaterialUiCatalog::ACTIVITY_CARDBOARD === $input->activity) {
            $grammage = $this->positiveDecimal($input->grammageGm2)
                ?? $this->positiveDecimal($this->catalog->cardboardGrammage($input->cardboardType));

            return $this->areaMass($input, $grammage, 'cardboard_dimensions_invalid');
        }
        if (in_array($input->activity, [MaterialUiCatalog::ACTIVITY_TEXTILES, MaterialUiCatalog::ACTIVITY_CANVAS], true)) {
            return $this->areaMass($input, $this->positiveDecimal($input->grammageGm2), 'textile_dimensions_invalid');
        }

        return $this->unavailable('dimension_conversion_unavailable');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function woodDimensions(MaterialEmissionInput $input): array
    {
        $units = $this->positiveDecimal($input->unitCount);
        $board = $this->catalog->woodBoard($input->boardFamily, $input->boardThickness);
        if (null !== $board && '' !== $board['grosor_m']) {
            $amount = $this->product([
                $units,
                $this->positiveDecimal($board['largo_m']),
                $this->positiveDecimal($board['ancho_m']),
                $this->positiveDecimal($board['grosor_m']),
                $this->positiveDecimal($board['densidad_kg_m3']),
            ]);

            return null === $amount ? $this->pending('wood_board_data_invalid') : $this->calculated($amount, 'kg');
        }

        if (null !== $board) {
            $amount = $this->product([
                $units,
                $this->positiveDecimal($input->lengthMeters),
                $this->positiveDecimal($input->widthMeters),
                $this->positiveDecimal($input->thicknessMeters),
                $this->positiveDecimal($board['densidad_kg_m3']),
            ]);

            return null === $amount ? $this->pending('wood_board_data_invalid') : $this->calculated($amount, 'kg');
        }

        $amount = $this->product([
            $units,
            $this->positiveDecimal($input->lengthMeters),
            $this->positiveDecimal($input->widthMeters),
            $this->positiveDecimal($input->thicknessMeters),
            $this->positiveDecimal($this->catalog->solidWoodDensity($input->woodType)),
        ]);

        return null === $amount ? $this->pending('wood_dimensions_invalid') : $this->calculated($amount, 'kg');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function paperPackages(MaterialEmissionInput $input): array
    {
        if (MaterialUiCatalog::ACTIVITY_PAPER !== $input->activity) {
            return $this->unavailable('package_conversion_unavailable');
        }
        $format = $this->catalog->paperFormat($input->paperFormat);
        $packages = $this->positiveDecimal($input->inputQuantity);
        $sheets = null === $input->sheetsPerPackage ? '500' : $this->positiveDecimal($input->sheetsPerPackage);
        if (null === $format || null === $packages || null === $sheets) {
            return $this->pending('paper_package_data_invalid');
        }

        return $this->calculated($this->divide($this->multiply($this->multiply($packages, $format['packageWeightKg']), $sheets), '500'), 'kg');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function grammage(MaterialEmissionInput $input): array
    {
        if (MaterialUiCatalog::ACTIVITY_PAPER === $input->activity) {
            $format = $this->catalog->paperFormat($input->paperFormat);
            $sheets = $this->positiveDecimal($input->unitCount ?? $input->inputQuantity);
            $grammage = $this->positiveDecimal($input->grammageGm2 ?? $format['grammage'] ?? null);
            if (null === $format || null === $format['surfaceM2'] || null === $sheets || null === $grammage) {
                return $this->pending('paper_grammage_data_invalid');
            }

            return $this->calculated($this->divide($this->product([$format['surfaceM2'], $sheets, $grammage]), '1000'), 'kg');
        }
        if (in_array($input->activity, [MaterialUiCatalog::ACTIVITY_TEXTILES, MaterialUiCatalog::ACTIVITY_CANVAS], true)) {
            return $this->areaMass($input, $this->positiveDecimal($input->grammageGm2), 'textile_grammage_data_invalid');
        }

        return $this->unavailable('grammage_conversion_unavailable');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function units(MaterialEmissionInput $input): array
    {
        $units = $this->positiveDecimal($input->unitCount ?? $input->inputQuantity);
        if (MaterialUiCatalog::ACTIVITY_CLOTHING === $input->activity) {
            return null === $units ? $this->pending('unit_count_invalid') : $this->calculated($units, 'ud');
        }
        if (in_array($input->activity, [MaterialUiCatalog::ACTIVITY_METAL, MaterialUiCatalog::ACTIVITY_PLASTERBOARD], true)) {
            $amount = $this->product([$units, $this->positiveDecimal($input->pieceWeightKg)]);

            return null === $amount ? $this->pending('piece_weight_data_invalid') : $this->calculated($amount, 'kg');
        }
        if (MaterialUiCatalog::ACTIVITY_BATTERIES === $input->activity) {
            if (null === $units) {
                return $this->pending('battery_unit_count_invalid');
            }
            $weight = $this->catalog->batteryWeight($input->batteryChemistry, $input->batterySize);
            if (null === $weight) {
                return $this->unavailable('battery_unique_weight_unavailable', 'kg');
            }

            return $this->calculated($this->multiply($units, $weight), 'kg');
        }

        return $this->unavailable('unit_conversion_unavailable');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function surface(MaterialEmissionInput $input): array
    {
        if (MaterialUiCatalog::ACTIVITY_CARPET !== $input->activity || !in_array($input->inputUnit, ['m²', 'm2'], true)) {
            return $this->unavailable('surface_conversion_unavailable', 'm²');
        }

        return $this->positiveResult($input->inputQuantity, 'm²', 'surface_invalid');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function volume(MaterialEmissionInput $input): array
    {
        $quantity = $this->positiveDecimal($input->inputQuantity);
        if (null === $quantity) {
            return $this->pending('volume_invalid');
        }
        $unit = strtolower(trim((string) $input->inputUnit));
        if (MaterialUiCatalog::ACTIVITY_SOLVENT === $input->activity) {
            $litres = match ($unit) {
                'l' => $quantity,
                'ml' => $this->divide($quantity, '1000'),
                'cl' => $this->divide($quantity, '100'),
                'gal_us', 'galón us', 'galon us', 'gal' => $this->multiply($quantity, '3.785411784'),
                default => null,
            };

            return null === $litres
                ? $this->unavailable('solvent_volume_unit_unsupported', 'l')
                : $this->calculated($litres, 'l');
        }
        if (in_array($input->activity, [MaterialUiCatalog::ACTIVITY_PAINT, MaterialUiCatalog::ACTIVITY_VARNISH], true)) {
            $density = $this->catalog->density((string) $input->activity);
            if ('l' !== $unit || null === $density) {
                return $this->unavailable('liquid_density_conversion_unavailable', 'kg');
            }

            return $this->calculated($this->multiply($quantity, $density), 'kg');
        }

        return $this->unavailable('volume_conversion_unavailable');
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function areaMass(MaterialEmissionInput $input, ?string $grammage, string $message): array
    {
        $amount = $this->product([
            $this->positiveDecimal($input->lengthMeters),
            $this->positiveDecimal($input->widthMeters),
            $this->positiveDecimal($input->unitCount),
            $grammage,
        ]);

        return null === $amount ? $this->pending($message) : $this->calculated($this->divide($amount, '1000'), 'kg');
    }

    /** @param list<?string> $values */
    private function product(array $values): ?string
    {
        $result = '1';
        foreach ($values as $value) {
            if (null === $value) {
                return null;
            }
            $result = $this->multiply($result, $value);
        }

        return $result;
    }

    private function positiveDecimal(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) || bccomp($value, '0', self::SCALE) <= 0) {
            return null;
        }

        return $this->trimDecimal($value);
    }

    private function multiply(string $left, string $right): string
    {
        return $this->trimDecimal(bcmul($left, $right, self::SCALE));
    }

    private function divide(string $left, string $right): string
    {
        return $this->trimDecimal(bcdiv($left, $right, self::SCALE));
    }

    private function trimDecimal(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @return array{status: string, amount: ?string, unit: ?string, message: ?string} */
    private function positiveResult(?string $amount, string $unit, string $message): array
    {
        $amount = $this->positiveDecimal($amount);

        return null === $amount ? $this->pending($message) : $this->calculated($amount, $unit);
    }

    /** @return array{status: string, amount: string, unit: string, message: null} */
    private function calculated(string $amount, string $unit): array
    {
        return ['status' => EmissionRecord::STATUS_CALCULATED, 'amount' => $amount, 'unit' => $unit, 'message' => null];
    }

    /** @return array{status: string, amount: null, unit: null, message: string} */
    private function pending(string $message): array
    {
        return ['status' => EmissionRecord::STATUS_PENDING_DATA, 'amount' => null, 'unit' => null, 'message' => $message];
    }

    /** @return array{status: string, amount: null, unit: ?string, message: string} */
    private function unavailable(string $message, ?string $unit = null): array
    {
        return ['status' => EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, 'amount' => null, 'unit' => $unit, 'message' => $message];
    }
}
