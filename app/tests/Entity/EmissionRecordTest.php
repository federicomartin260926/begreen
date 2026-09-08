<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use PHPUnit\Framework\TestCase;

final class EmissionRecordTest extends TestCase
{
    public function testModernRecordUsesExplicitCategory(): void
    {
        $category = (new Category())->setName('Transporte');
        $record = (new EmissionRecord())->setCategory($category);

        self::assertSame($category, $record->getCategory());
        self::assertSame($category, $record->getEffectiveCategory());
    }
}
