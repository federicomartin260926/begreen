<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Water;

use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionResult;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Water\WaterFactorResolution;
use App\Service\Emission\Water\WaterFactorTrace;
use PHPUnit\Framework\TestCase;

final class WaterEmissionSnapshotTest extends TestCase
{
    public function testRoundTripPreservesInputCalculationPresentationAndTrace(): void
    {
        $input = $this->input();
        $trace = new WaterFactorTrace(
            component: 'urban_water_cycle',
            factorType: 'urban_water_cycle',
            activityYear: 2024,
            factorYear: 2024,
            factorValue: '0.517',
            factorUnit: 'kgCO2e/m3',
            source: 'OCCC',
            sourceDetail: null,
            sourceEdition: '2025',
            sourceGeography: 'Cataluña',
            targetGeography: 'España/ES',
            isFallback: false,
            fallbackReason: null,
            isGeographicProxy: true,
            dataQuality: WaterFactorResolution::QUALITY_MEDIUM,
            normalizedAmount: '1',
            normalizedUnit: 'm3',
            componentEmissionKgCo2e: '0.517',
            criteria: ['geography' => 'Cataluña', 'factorType' => 'urban_water_cycle', 'unit' => 'm3'],
            metadata: ['sourceUrl' => 'https://example.test/water'],
        );
        $result = new WaterEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '0.517',
            '1',
            'm3',
            2024,
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            [$trace],
        );
        $snapshot = new WaterEmissionSnapshot();
        $encoded = $snapshot->encode($input, $result, ['label' => 'Limpieza plató']);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decodeInput($encoded);

        self::assertSame('water-v1', $data['version']);
        self::assertSame('water-v1', $data['calculatorVersion']);
        self::assertSame('0.517', $data['calculation']['emissionKgCo2e']);
        self::assertSame('Cataluña', $data['calculation']['factorTraces'][0]['sourceGeography']);
        self::assertSame('0.517', $data['calculation']['factorTraces'][0]['componentEmissionKgCo2e']);
        self::assertSame('Limpieza plató', $snapshot->decodePresentation($encoded)['label']);
        self::assertSame('2024-01-01', $decoded->startDate->format('Y-m-d'));
        self::assertSame('2024-12-31', $decoded->endDate->format('Y-m-d'));
        self::assertSame('ES', $decoded->country);
        self::assertSame(WaterEmissionInput::USE_CLEANING, $decoded->waterUseType);
        self::assertSame('1000', $decoded->volumeInput);
        self::assertSame('L', $decoded->volumeInputUnit);
        self::assertSame(WaterEmissionInput::DESTINATION_SEWER, $decoded->destination);
    }

    public function testRejectsUnsupportedVersionAndMalformedJson(): void
    {
        foreach (['{"version":"water-v0","input":{}}', 'not-json'] as $invalid) {
            try {
                (new WaterEmissionSnapshot())->decodeInput($invalid);
                self::fail('Invalid snapshot must be rejected.');
            } catch (\JsonException|\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPendingInputRoundTripPreservesNullableFields(): void
    {
        $input = new WaterEmissionInput(null, null, null, null, null, null, null);
        $result = new WaterEmissionResult(
            EmissionRecord::STATUS_PENDING_DATA,
            null,
            null,
            null,
            null,
            null,
            messages: ['dates_required'],
        );
        $snapshot = new WaterEmissionSnapshot();

        $decoded = $snapshot->decodeInput($snapshot->encode($input, $result));

        self::assertNull($decoded->startDate);
        self::assertNull($decoded->endDate);
        self::assertNull($decoded->country);
        self::assertNull($decoded->waterUseType);
        self::assertNull($decoded->volumeInput);
        self::assertNull($decoded->volumeInputUnit);
        self::assertNull($decoded->destination);
    }

    public function testDecodeRejectsInvalidDateValueAndType(): void
    {
        foreach (['not-a-date', 20240101] as $startDate) {
            $snapshot = json_encode([
                'version' => WaterEmissionSnapshot::VERSION,
                'input' => ['startDate' => $startDate],
            ], JSON_THROW_ON_ERROR);

            try {
                (new WaterEmissionSnapshot())->decodeInput($snapshot);
                self::fail('Invalid snapshot date must be rejected.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDecodeRejectsMissingInputFields(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new WaterEmissionSnapshot())->decodeInput('{"version":"water-v1","input":{}}');
    }

    public function testRecognizesOnlyModernWaterRecordsWithValidVersion(): void
    {
        $category = (new Category())->setName('Agua');
        $property = (new \ReflectionClass(Category::class))->getProperty('id');
        $property->setValue($category, 7);

        $result = new WaterEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '0.517',
            '1',
            'm3',
            2024,
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
        );
        $details = (new WaterEmissionSnapshot())->encode($this->input(), $result);
        $record = (new EmissionRecord())
            ->setCategory($category)

            ->setCalculationDetails($details);

        $snapshot = new WaterEmissionSnapshot();
        self::assertTrue($snapshot->isWaterV1Record($record, 7));
        self::assertFalse($snapshot->isWaterV1Record($record, 8));
        $record->setCalculationDetails('{"version":"water-v0"}');
        self::assertFalse($snapshot->isWaterV1Record($record, 7));
    }

    private function input(): WaterEmissionInput
    {
        return new WaterEmissionInput(
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-12-31'),
            'ES',
            WaterEmissionInput::USE_CLEANING,
            '1000',
            'L',
            WaterEmissionInput::DESTINATION_SEWER,
        );
    }
}
