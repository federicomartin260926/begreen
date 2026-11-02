<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Catering;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Catering\CateringEmissionCalculator;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use PHPUnit\Framework\TestCase;

/** #78 C5: el gas de Catering debe reutilizar factores oficiales existentes en Energia. */
final class CateringGasRegressionTest extends TestCase
{
    public function testSpainPropaneIn2026ReusesMitecoFactorWith2025FactorYear(): void
    {
        $result = $this->calculator()->calculate($this->gas('ESP', 'propane', 2026));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('20', $result->normalizedAmount);
        self::assertSame('kg', $result->normalizedUnit);
        self::assertSame('59.32', $result->emissionKgCo2e);
        self::assertCount(1, $result->factorTraces);
        $trace = $result->factorTraces[0];
        self::assertSame('gas', $trace->component);
        self::assertSame('ENE_TEST_ES_PROP_2026', $trace->factorId);
        self::assertSame(2025, $trace->factorYear);
        self::assertSame('MITECO', $trace->source);
        self::assertFalse($trace->isGeographicProxy);
    }

    public function testFranceButaneIn2026ShowsUkGeographicProxy(): void
    {
        $result = $this->calculator()->calculate($this->gas('FRA', 'butane', 2026));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('60.6676134', $result->emissionKgCo2e);
        self::assertSame('ENE_TEST_OTHER_BUT_2026', $result->factorTraces[0]->factorId);
        self::assertSame('DEFRA', $result->factorTraces[0]->source);
        self::assertTrue($result->factorTraces[0]->isGeographicProxy);
        self::assertSame('Reino Unido', $result->factorTraces[0]->proxyGeography);
    }

    public function testOtherCombinationsPropaneFranceAndButaneSpain(): void
    {
        $frenchPropane = $this->calculator()->calculate($this->gas('FRA', 'propane', 2026));
        self::assertSame('59.9526466', $frenchPropane->emissionKgCo2e);
        self::assertTrue($frenchPropane->factorTraces[0]->isGeographicProxy);

        $spanishButane = $this->calculator()->calculate($this->gas('ESP', 'butane', 2025));
        self::assertSame('59.92', $spanishButane->emissionKgCo2e);
        self::assertFalse($spanishButane->factorTraces[0]->isGeographicProxy);
    }

    public function testWithoutApplicableFactorStillDoesNotCalculate(): void
    {
        $result = $this->calculator()->calculate($this->gas('ESP', 'propane', 2023));
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertSame('20', $result->normalizedAmount);
        self::assertSame([], $result->factorTraces);
    }

    public function testInvalidGasInputStaysPending(): void
    {
        $result = $this->calculator()->calculate($this->gas('ESP', 'unknown', 2026));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
        self::assertNull($result->emissionKgCo2e);
    }

    public function testGasPreviewUsesTranslatedGasLabel(): void
    {
        $js = file_get_contents(__DIR__.'/../../../../assets/controllers/catering_v1_form_controller.js');
        $twig = file_get_contents(__DIR__.'/../../../../templates/backend/emission/catering_v1_form.html.twig');
        self::assertIsString($js);
        self::assertIsString($twig);
        self::assertStringContainsString("trace.component === 'gas' ? this.i18nValue.gas", $js);
        self::assertStringContainsString("gas: 'backend.emission.catering_v1.activities.gas'|trans,", $twig);
    }

    private function gas(string $country, string $fuel, int $year): CateringEmissionInput
    {
        return new CateringEmissionInput(
            new \DateTimeImmutable(sprintf('%d-06-15', $year)),
            new \DateTimeImmutable(sprintf('%d-06-15', $year)),
            $country,
            CateringEmissionInput::TYPE_GAS,
            gasType: $fuel,
            cylinderCount: '2',
            kgPerCylinder: '10',
        );
    }

    private function calculator(): CateringEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        foreach ([
            ['ESPAÑA', 'Gas propano', 2026, 2025, '2.966', 'MITECO', 'ENE_TEST_ES_PROP_2026'],
            ['FUERA DE ESPAÑA', 'Gas butano', 2026, 2026, '3.03338067', 'DEFRA', 'ENE_TEST_OTHER_BUT_2026'],
            ['FUERA DE ESPAÑA', 'Gas propano', 2026, 2026, '2.99763233', 'DEFRA', 'ENE_TEST_OTHER_PROP_2026'],
            ['ESPAÑA', 'Gas butano', 2025, 2025, '2.996', 'MITECO', 'ENE_TEST_ES_BUT_2025'],
        ] as [$geography, $fuel, $activityYear, $factorYear, $value, $source, $id]) {
            $criteria = [
                'geography' => $geography,
                'category' => 'COMBUSTIÓN ESTACIONARIA',
                'activity' => $fuel,
                'labeling' => '',
                'supplier' => '',
                'unit' => 'kg',
            ];
            $factors[] = (new EmissionFactor())
                ->setFactorId($id)
                ->setCategoryKey('energy')
                ->setFunctionalKey($keyGenerator->generate($criteria))
                ->setCriteria($criteria)
                ->setActivityYear($activityYear)
                ->setYear($factorYear)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
                ->setValue($value)
                ->setUnit('kgCO2e/kg')
                ->setSource($source)
                ->setMetadata([]);
        }

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use ($factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ($categoryKey === $factor->getCategoryKey()
                        && $functionalKey === $factor->getFunctionalKey()
                        && $activityYear === $factor->getActivityYear()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new CateringEmissionCalculator(new CateringFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)));
    }
}
