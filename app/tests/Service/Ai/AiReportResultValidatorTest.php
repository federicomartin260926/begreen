<?php

namespace App\Tests\Service\Ai;

use App\Exception\Ai\AiInvalidStructureException;
use App\Service\Ai\AiReportResultValidator;
use PHPUnit\Framework\TestCase;

final class AiReportResultValidatorTest extends TestCase
{
    public function testAcceptsExactlyTheRequestedCategorySummaries(): void
    {
        $result = (new AiReportResultValidator())->validate($this->data([
            ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
            ['categoryKey' => 'category:2', 'summary' => 'Resumen dos.'],
        ]), ['category:1', 'category:2']);

        self::assertSame(['category:1', 'category:2'], array_column($result->categorySummaries, 'categoryKey'));
    }

    public function testRejectsAnUnknownCategory(): void
    {
        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate($this->data([
            ['categoryKey' => 'category:unknown', 'summary' => 'Resumen ajeno.'],
        ]), ['category:1']);
    }

    public function testRejectsAnOmittedCategory(): void
    {
        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate($this->data([
            ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
        ]), ['category:1', 'category:2']);
    }

    public function testRejectsADuplicateCategory(): void
    {
        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate($this->data([
            ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
            ['categoryKey' => 'category:1', 'summary' => 'Resumen repetido.'],
        ]), ['category:1']);
    }

    /** @param list<array{categoryKey:string, summary:string}> $categorySummaries */
    private function data(array $categorySummaries): array
    {
        return [
            'generalConclusion' => 'Conclusión general.',
            'categorySummaries' => $categorySummaries,
        ];
    }
}
