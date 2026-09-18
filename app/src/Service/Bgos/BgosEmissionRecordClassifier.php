<?php

namespace App\Service\Bgos;

use App\Entity\EmissionRecord;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionSnapshot;

final class BgosEmissionRecordClassifier
{
    public function __construct(
        private readonly BgosSubcategoryCatalog $catalog,
        private readonly TransportEmissionSnapshot $transportSnapshot,
        private readonly EnergyEmissionSnapshot $energySnapshot,
        private readonly WaterEmissionSnapshot $waterSnapshot,
        private readonly AccommodationEmissionSnapshot $accommodationSnapshot,
        private readonly CateringEmissionSnapshot $cateringSnapshot,
        private readonly MaterialEmissionSnapshot $materialSnapshot,
        private readonly WasteEmissionSnapshot $wasteSnapshot,
    ) {
    }

    /** @return array{categoryKey: string, subcategoryKey: string}|null */
    public function classify(EmissionRecord $record): ?array
    {
        $snapshot = $record->getCalculationDetails();
        if (null === $snapshot || '' === trim($snapshot)) {
            return null;
        }

        try {
            $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            $version = is_array($data) && is_string($data['version'] ?? null)
                ? $data['version']
                : null;

            [$categoryKey, $sourceKey] = match ($version) {
                TransportEmissionSnapshot::VERSION => [
                    'transport',
                    $this->transportSnapshot->decode($snapshot)->category,
                ],
                EnergyEmissionSnapshot::VERSION => [
                    'energy',
                    $this->energySnapshot->decodeInput($snapshot)->family,
                ],
                WaterEmissionSnapshot::VERSION => [
                    'water',
                    $this->waterSnapshot->decodeInput($snapshot)->waterUseType,
                ],
                AccommodationEmissionSnapshot::VERSION => [
                    'accommodation',
                    $this->accommodationSnapshot->decodeInput($snapshot)->accommodationType,
                ],
                CateringEmissionSnapshot::VERSION => [
                    'catering',
                    $this->cateringSnapshot->decodeInput($snapshot)->activityType,
                ],
                MaterialEmissionSnapshot::VERSION => [
                    'materials',
                    $this->materialSnapshot->decodeInput($snapshot)->activity,
                ],
                WasteEmissionSnapshot::VERSION => [
                    'waste',
                    $this->wasteSnapshot->decodeInput($snapshot)->wasteType,
                ],
                default => [null, null],
            };
        } catch (\JsonException|\UnexpectedValueException|\TypeError|\ValueError) {
            return null;
        }

        if (!is_string($categoryKey) || !is_string($sourceKey) || '' === $sourceKey) {
            return null;
        }

        $definition = $this->catalog->findBySourceKey($categoryKey, $sourceKey);
        if (null === $definition) {
            return null;
        }

        return [
            'categoryKey' => $definition['categoryKey'],
            'subcategoryKey' => $definition['subcategoryKey'],
        ];
    }
}
