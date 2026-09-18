<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\EmissionRecord;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionSnapshot;

final class BgosEmissionRecordTemporalMapper
{
    private const PHASE_KEYS = [
        'preproduccion',
        'actividad',
        'postproduccion',
    ];

    public function __construct(
        private readonly BgosEmissionRecordClassifier $classifier,
        private readonly TransportEmissionSnapshot $transportSnapshot,
        private readonly EnergyEmissionSnapshot $energySnapshot,
        private readonly WaterEmissionSnapshot $waterSnapshot,
        private readonly AccommodationEmissionSnapshot $accommodationSnapshot,
        private readonly CateringEmissionSnapshot $cateringSnapshot,
        private readonly MaterialEmissionSnapshot $materialSnapshot,
        private readonly WasteEmissionSnapshot $wasteSnapshot,
    ) {
    }

    public function map(EmissionRecord $record): ?BgosTemporalRecord
    {
        $identity = $this->classifier->classify($record);
        $recordId = $record->getId();
        $snapshot = $record->getCalculationDetails();
        if (null === $identity || null === $recordId || null === $snapshot) {
            return null;
        }

        $dates = $this->decodeDates($identity['categoryKey'], $snapshot);
        if (null === $dates || null === $dates[0]) {
            return null;
        }

        $startDate = $this->normalizeDate($dates[0]);
        $endDate = $this->normalizeDate($dates[1] ?? $dates[0]);
        if ($endDate < $startDate) {
            return null;
        }

        $phaseKey = $record->getPhase()->getPhase();
        if (!is_string($phaseKey) || !in_array($phaseKey, self::PHASE_KEYS, true)) {
            return null;
        }

        return new BgosTemporalRecord(
            recordId: $recordId,
            categoryKey: $identity['categoryKey'],
            subcategoryKey: $identity['subcategoryKey'],
            phaseKey: $phaseKey,
            startDate: $startDate,
            endDate: $endDate,
            totalKgCo2e: $record->getEmission(),
            status: $record->getStatus(),
        );
    }

    /** @return array{0: ?\DateTimeInterface, 1: ?\DateTimeInterface}|null */
    private function decodeDates(string $categoryKey, string $snapshot): ?array
    {
        try {
            $input = match ($categoryKey) {
                'transport' => $this->transportSnapshot->decode($snapshot),
                'energy' => $this->energySnapshot->decodeInput($snapshot),
                'water' => $this->waterSnapshot->decodeInput($snapshot),
                'accommodation' => $this->accommodationSnapshot->decodeInput($snapshot),
                'catering' => $this->cateringSnapshot->decodeInput($snapshot),
                'materials' => $this->materialSnapshot->decodeInput($snapshot),
                'waste' => $this->wasteSnapshot->decodeInput($snapshot),
                default => null,
            };
        } catch (\JsonException|\UnexpectedValueException|\TypeError|\ValueError) {
            return null;
        }

        return null === $input ? null : [$input->startDate, $input->endDate];
    }

    private function normalizeDate(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }
}
