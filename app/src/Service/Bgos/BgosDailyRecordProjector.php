<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final class BgosDailyRecordProjector
{
    /** @return list<BgosDailyRecord> */
    public function project(BgosTemporalRecord $record): array
    {
        $days = (int) $record->startDate->diff($record->endDate)->days + 1;

        if ($days < 1) {
            return [];
        }

        $dailyKgCo2e = null === $record->totalKgCo2e
            ? null
            : $record->totalKgCo2e / $days;

        $result = [];

        for ($offset = 0; $offset < $days; ++$offset) {
            $result[] = new BgosDailyRecord(
                recordId: $record->recordId,
                categoryKey: $record->categoryKey,
                subcategoryKey: $record->subcategoryKey,
                phaseKey: $record->phaseKey,
                date: $record->startDate->modify(sprintf('+%d days', $offset)),
                kgCo2e: $dailyKgCo2e,
                status: $record->status,
            );
        }

        return $result;
    }
}
