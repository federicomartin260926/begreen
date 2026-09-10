<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission;

use App\Service\Emission\EmissionTraceabilityPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmissionTraceabilityPresenterTest extends TestCase
{
    #[DataProvider('snapshotProvider')]
    public function testNormalizesRepresentativeSnapshots(array $snapshot, string $expectedFactorId): void
    {
        $traces = (new EmissionTraceabilityPresenter())->extract($snapshot);

        self::assertCount(1, $traces);
        self::assertSame($expectedFactorId, $traces[0]['factorId']);
        self::assertArrayHasKey('factorActivityYear', $traces[0]);
        self::assertArrayHasKey('factorVersion', $traces[0]);
    }

    public static function snapshotProvider(): iterable
    {
        yield 'transport' => [['version' => 'transport-v20', 'factor' => ['factorId' => 'TRA-1', 'activityYear' => 2025, 'value' => '1']], 'TRA-1'];
        yield 'energy' => [['calculation' => ['factorTraces' => [['factorId' => 'ENE-1', 'factorActivityYear' => 2024, 'factorValue' => '2']]]], 'ENE-1'];
        yield 'water' => [['calculation' => ['factorTraces' => [['factorId' => 'WAT-1', 'factorValue' => '3', 'sourceEdition' => '2025']]]], 'WAT-1'];
        yield 'accommodation' => [['calculation' => ['factorTraces' => [['factorId' => 'ACC-1', 'effectiveFactorValue' => '4']]]], 'ACC-1'];
        yield 'catering' => [['calculation' => ['factorTraces' => [['factorId' => 'CAT-1', 'factorValue' => '5', 'isTemporalFallback' => true]]]], 'CAT-1'];
        yield 'material' => [['calculation' => ['factorTraces' => [['factorId' => 'MAT-1', 'factorValue' => '0', 'isFallback' => true]]]], 'MAT-1'];
        yield 'waste' => [['calculation' => ['factorTraces' => [['factorId' => 'WAS-1', 'factorValue' => '6', 'isGeographicProxy' => true]]]], 'WAS-1'];
    }

    public function testNormalizesAliasesPreservesMultipleTracesAndFiltersTechnicalVersion(): void
    {
        $traces = (new EmissionTraceabilityPresenter())->extract([
            'version' => 'water-v1',
            'calculatorVersion' => 'water-v1',
            'calculation' => ['factorTraces' => [
                ['factorId' => 'WAT-1', 'factorValue' => '0', 'fallback' => true, 'geographicProxy' => true, 'proxyReason' => 'proxy', 'proxyGeography' => 'GBR', 'factorVersion' => 'water-v1'],
                ['factorId' => 'WAT-2', 'factorValue' => '1', 'isFallback' => true, 'isGeographicProxy' => true, 'qualityStatus' => 'ALTA'],
            ]],
        ]);

        self::assertCount(2, $traces);
        self::assertTrue($traces[0]['isFallback']);
        self::assertTrue($traces[0]['isGeographicProxy']);
        self::assertSame('proxy', $traces[0]['geographicProxyReason']);
        self::assertSame('GBR', $traces[0]['sourceGeography']);
        self::assertNull($traces[0]['factorVersion']);
        self::assertSame('ALTA', $traces[1]['dataQuality']);
    }

    public function testReturnsNoTraceWithoutFactorEvidence(): void
    {
        self::assertSame([], (new EmissionTraceabilityPresenter())->extract([
            'version' => 'energy-v1',
            'calculation' => ['status' => 'PENDING_DATA', 'factorTraces' => []],
        ]));
    }
}
