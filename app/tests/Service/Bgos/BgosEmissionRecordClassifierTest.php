<?php

namespace App\Tests\Service\Bgos;

use App\Entity\EmissionRecord;
use App\Service\Bgos\BgosEmissionRecordClassifier;
use App\Service\Bgos\BgosSubcategoryCatalog;
use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Material\MaterialEmissionInput;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BgosEmissionRecordClassifierTest extends KernelTestCase
{
    private BgosEmissionRecordClassifier $classifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->classifier = new BgosEmissionRecordClassifier(
            self::getContainer()->get(BgosSubcategoryCatalog::class),
            new TransportEmissionSnapshot(),
            new EnergyEmissionSnapshot(),
            new WaterEmissionSnapshot(),
            new AccommodationEmissionSnapshot(),
            new CateringEmissionSnapshot(),
            new MaterialEmissionSnapshot(),
            new WasteEmissionSnapshot(),
        );
    }

    public function testClassifiesLocalTransportAsPeople(): void
    {
        self::assertSame(
            ['categoryKey' => 'transport', 'subcategoryKey' => 'people'],
            $this->classify($this->transportSnapshot('local', 'car')),
        );
    }

    public function testClassifiesTravelTransportAsPeople(): void
    {
        self::assertSame(
            ['categoryKey' => 'transport', 'subcategoryKey' => 'people'],
            $this->classify($this->transportSnapshot('travel', 'plane')),
        );
    }

    public function testClassifiesFreightTransportAsFreight(): void
    {
        self::assertSame(
            ['categoryKey' => 'transport', 'subcategoryKey' => 'freight'],
            $this->classify($this->transportSnapshot('freight', 'freight_van')),
        );
    }

    public function testClassifiesEnergyByFamily(): void
    {
        $input = new EnergyEmissionInput(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
            'ESP',
        );

        self::assertSame(
            ['categoryKey' => 'energy', 'subcategoryKey' => 'electricity'],
            $this->classify($this->snapshot(
                EnergyEmissionSnapshot::VERSION,
                (new EnergyEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testClassifiesWaterByUseType(): void
    {
        $input = new WaterEmissionInput(null, null, 'ESP', WaterEmissionInput::USE_CLEANING, '1', 'm3', 'sewer');

        self::assertSame(
            ['categoryKey' => 'water', 'subcategoryKey' => 'limpieza'],
            $this->classify($this->snapshot(
                WaterEmissionSnapshot::VERSION,
                (new WaterEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testClassifiesAccommodationByType(): void
    {
        $input = new AccommodationEmissionInput(null, null, 'ESP', AccommodationEmissionInput::TYPE_HOTEL, null, '1', '1', '1');

        self::assertSame(
            ['categoryKey' => 'accommodation', 'subcategoryKey' => 'hotel'],
            $this->classify($this->snapshot(
                AccommodationEmissionSnapshot::VERSION,
                (new AccommodationEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testClassifiesCateringByActivityType(): void
    {
        $input = new CateringEmissionInput(null, null, 'ESP', CateringEmissionInput::TYPE_MEAL);

        self::assertSame(
            ['categoryKey' => 'catering', 'subcategoryKey' => 'meal'],
            $this->classify($this->snapshot(
                CateringEmissionSnapshot::VERSION,
                (new CateringEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testClassifiesMaterialByActivity(): void
    {
        $input = new MaterialEmissionInput(activity: 'Cartón', family: 'cardboard');

        self::assertSame(
            ['categoryKey' => 'materials', 'subcategoryKey' => 'carton'],
            $this->classify($this->snapshot(
                MaterialEmissionSnapshot::VERSION,
                (new MaterialEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testClassifiesWasteByWasteType(): void
    {
        $input = new WasteEmissionInput(null, null, 'ESP', 'Aceites usados', null, null, '1', 'kg');

        self::assertSame(
            ['categoryKey' => 'waste', 'subcategoryKey' => 'aceites-usados'],
            $this->classify($this->snapshot(
                WasteEmissionSnapshot::VERSION,
                (new WasteEmissionSnapshot())->inputToArray($input),
            )),
        );
    }

    public function testUnknownSourceKeyReturnsNull(): void
    {
        $input = new WaterEmissionInput(null, null, 'ESP', 'invented-use', '1', 'm3', 'sewer');

        self::assertNull($this->classify($this->snapshot(
            WaterEmissionSnapshot::VERSION,
            (new WaterEmissionSnapshot())->inputToArray($input),
        )));
    }

    public function testUnsupportedOrInvalidRecordReturnsNull(): void
    {
        self::assertNull($this->classify('{"version":"legacy","input":{}}'));
        self::assertNull($this->classify('{invalid-json'));
        self::assertNull($this->classify('{"version":"water-v1","input":{}}'));
        self::assertNull($this->classify(null));
    }

    private function transportSnapshot(string $category, string $mode): string
    {
        $input = new TransportEmissionInput(
            category: $category,
            mode: $mode,
            method: 'distance',
            country: 'ES',
            startDate: new \DateTimeImmutable('2026-01-01'),
            endDate: new \DateTimeImmutable('2026-01-01'),
            activityValue: '1',
            activityUnit: 'km',
        );

        return $this->snapshot(
            TransportEmissionSnapshot::VERSION,
            (new TransportEmissionSnapshot())->inputToArray($input),
        );
    }

    /** @param array<string, mixed> $input */
    private function snapshot(string $version, array $input): string
    {
        return json_encode(['version' => $version, 'input' => $input], JSON_THROW_ON_ERROR);
    }

    private function classify(?string $snapshot): ?array
    {
        $record = (new EmissionRecord())->setCalculationDetails($snapshot);

        return $this->classifier->classify($record);
    }
}
