<?php

namespace App\Tests\Entity;

use App\Entity\EmissionRecord;
use PHPUnit\Framework\TestCase;

final class EmissionRecordContractTest extends TestCase
{
    public function testPendingModernRecordPreservesUnknownAmountAndEmission(): void
    {
        $record = (new EmissionRecord())
            ->setAmount(null)
            ->setEmission(null)
            ->setStatus(EmissionRecord::STATUS_PENDING_DATA);

        self::assertNull($record->getAmount());
        self::assertNull($record->getEmission());
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $record->getStatus());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, (new EmissionRecord())->getStatus());

        $record->setStatus(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE);
        self::assertNull($record->getEmission());
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $record->getStatus());
    }
}
