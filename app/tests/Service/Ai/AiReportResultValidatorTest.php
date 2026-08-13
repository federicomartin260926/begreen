<?php

namespace App\Tests\Service\Ai;

use App\Exception\Ai\AiInvalidStructureException;
use App\Service\Ai\AiReportResultValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiReportResultValidatorTest extends TestCase
{
    public function testAcceptsExactGeneralAndFutureCategorySummaries(): void
    {
        $result = (new AiReportResultValidator())->validate(
            $this->data(
                [
                    ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
                    ['categoryKey' => 'category:2', 'summary' => 'Resumen dos.'],
                ],
                [['categoryKey' => 'category:2', 'summary' => 'Horizonte dos.']],
            ),
            ['category:1', 'category:2'],
            ['category:2'],
        );

        self::assertSame(['category:1', 'category:2'], array_column($result->categorySummaries, 'categoryKey'));
        self::assertSame(['category:2'], array_column($result->categoryFutureSummaries, 'categoryKey'));
        self::assertSame('Cierre final.', $result->finalConclusion);
    }

    #[DataProvider('invalidFutureSummariesProvider')]
    public function testRejectsInvalidFutureSummaries(array $futureSummaries, array $expectedFutureKeys): void
    {
        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate(
            $this->data(
                [['categoryKey' => 'category:1', 'summary' => 'Resumen uno.']],
                $futureSummaries,
            ),
            ['category:1'],
            $expectedFutureKeys,
        );
    }

    public static function invalidFutureSummariesProvider(): iterable
    {
        yield 'unknown' => [[['categoryKey' => 'category:unknown', 'summary' => 'Ajeno.']], ['category:1']];
        yield 'omitted' => [[], ['category:1']];
        yield 'additional when none expected' => [[['categoryKey' => 'category:1', 'summary' => 'Extra.']], []];
        yield 'duplicate' => [[
            ['categoryKey' => 'category:1', 'summary' => 'Uno.'],
            ['categoryKey' => 'category:1', 'summary' => 'Dos.'],
        ], ['category:1']];
        yield 'empty summary' => [[['categoryKey' => 'category:1', 'summary' => '  ']], ['category:1']];
    }

    #[DataProvider('invalidGeneralSummariesProvider')]
    public function testKeepsRejectingInvalidGeneralSummaries(array $summaries, array $expectedKeys): void
    {
        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate($this->data($summaries, []), $expectedKeys, []);
    }

    public static function invalidGeneralSummariesProvider(): iterable
    {
        yield 'unknown' => [[['categoryKey' => 'category:unknown', 'summary' => 'Ajeno.']], ['category:1']];
        yield 'omitted' => [[['categoryKey' => 'category:1', 'summary' => 'Uno.']], ['category:1', 'category:2']];
        yield 'empty array' => [[], ['category:1']];
        yield 'duplicate' => [[
            ['categoryKey' => 'category:1', 'summary' => 'Uno.'],
            ['categoryKey' => 'category:1', 'summary' => 'Dos.'],
        ], ['category:1']];
        yield 'empty summary' => [[['categoryKey' => 'category:1', 'summary' => '']], ['category:1']];
    }

    public function testRejectsMissingRequiredRootField(): void
    {
        $data = $this->data([], []);
        unset($data['categoryFutureSummaries']);

        $this->expectException(AiInvalidStructureException::class);

        (new AiReportResultValidator())->validate($data, [], []);
    }

    /**
     * @param list<array{categoryKey:string, summary:string}> $categorySummaries
     * @param list<array{categoryKey:string, summary:string}> $categoryFutureSummaries
     */
    private function data(array $categorySummaries, array $categoryFutureSummaries): array
    {
        return [
            'generalConclusion' => 'Conclusión general.',
            'categorySummaries' => $categorySummaries,
            'categoryFutureSummaries' => $categoryFutureSummaries,
            'finalConclusion' => 'Cierre final.',
        ];
    }
}
