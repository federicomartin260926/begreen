<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\EmissionRecord;
use App\Entity\ProjectPhaseDate;
use App\Service\Bgos\BgosEmissionRecordClassifier;
use App\Service\Bgos\BgosEmissionRecordTemporalMapper;
use App\Service\Bgos\BgosSubcategoryCatalog;
use App\Service\Bgos\BgosTemporalRecord;
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

final class BgosEmissionRecordTemporalMapperTest extends KernelTestCase
{
    private BgosEmissionRecordTemporalMapper $mapper;

    protected function setUp(): void
    {
        self::bootKernel();

        $transportSnapshot = new TransportEmissionSnapshot();
        $energySnapshot = new EnergyEmissionSnapshot();
        $waterSnapshot = new WaterEmissionSnapshot();
        $accommodationSnapshot = new AccommodationEmissionSnapshot();
        $cateringSnapshot = new CateringEmissionSnapshot();
        $materialSnapshot = new MaterialEmissionSnapshot();
        $wasteSnapshot = new WasteEmissionSnapshot();
        $classifier = new BgosEmissionRecordClassifier(
            self::getContainer()->get(BgosSubcategoryCatalog::class),
            $transportSnapshot,
            $energySnapshot,
            $waterSnapshot,
            $accommodationSnapshot,
            $cateringSnapshot,
            $materialSnapshot,
            $wasteSnapshot,
        );
        $this->mapper = new BgosEmissionRecordTemporalMapper(
            $classifier,
            $transportSnapshot,
            $energySnapshot,
            $waterSnapshot,
            $accommodationSnapshot,
            $cateringSnapshot,
            $materialSnapshot,
            $wasteSnapshot,
        );
    }

    public function testMapsTransportRangeAndKeepsRecordValues(): void
    {
        $snapshot = new TransportEmissionSnapshot();
        $input = new TransportEmissionInput(
            category: 'local',
            mode: 'car',
            method: 'distance',
            country: 'ES',
            startDate: new \DateTimeImmutable('2026-09-10 23:00:00'),
            endDate: new \DateTimeImmutable('2026-09-12 01:00:00'),
            activityValue: '1',
            activityUnit: 'km',
        );
        $record = $this->record(
            $this->snapshot(TransportEmissionSnapshot::VERSION, $snapshot->inputToArray($input)),
            phaseKey: 'actividad',
            emission: 90.123456,
            status: EmissionRecord::STATUS_PENDING_DATA,
        );

        $temporal = $this->mapper->map($record);

        self::assertInstanceOf(BgosTemporalRecord::class, $temporal);
        self::assertSame(123, $temporal->recordId);
        self::assertSame('transport', $temporal->categoryKey);
        self::assertSame('people', $temporal->subcategoryKey);
        self::assertSame('actividad', $temporal->phaseKey);
        self::assertSame('2026-09-10 00:00:00', $temporal->startDate->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-12 00:00:00', $temporal->endDate->format('Y-m-d H:i:s'));
        self::assertSame(90.123456, $temporal->totalKgCo2e);
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $temporal->status);
    }

    public function testMapsEnergyRange(): void
    {
        $input = new EnergyEmissionInput(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            'ESP',
        );

        $this->assertMappedRange(
            $this->snapshot(EnergyEmissionSnapshot::VERSION, (new EnergyEmissionSnapshot())->inputToArray($input)),
            'energy',
            'electricity',
        );
    }

    public function testMapsWater(): void
    {
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );

        $this->assertMappedRange(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
            'water',
            'limpieza',
        );
    }

    public function testMapsAccommodation(): void
    {
        $input = new AccommodationEmissionInput(
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            'ESP',
            AccommodationEmissionInput::TYPE_HOTEL,
            null,
            '1',
            '1',
            '1',
        );

        $this->assertMappedRange(
            $this->snapshot(AccommodationEmissionSnapshot::VERSION, (new AccommodationEmissionSnapshot())->inputToArray($input)),
            'accommodation',
            'hotel',
        );
    }

    public function testMapsCatering(): void
    {
        $input = new CateringEmissionInput(
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            'ESP',
            CateringEmissionInput::TYPE_MEAL,
        );

        $this->assertMappedRange(
            $this->snapshot(CateringEmissionSnapshot::VERSION, (new CateringEmissionSnapshot())->inputToArray($input)),
            'catering',
            'meal',
        );
    }

    public function testMapsMaterial(): void
    {
        $input = new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2026-09-10'),
            endDate: new \DateTimeImmutable('2026-09-11'),
            activity: 'Cartón',
            family: 'cardboard',
        );

        $this->assertMappedRange(
            $this->snapshot(MaterialEmissionSnapshot::VERSION, (new MaterialEmissionSnapshot())->inputToArray($input)),
            'materials',
            'carton',
        );
    }

    public function testMapsWaste(): void
    {
        $input = new WasteEmissionInput(
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            'ESP',
            'Aceites usados',
            null,
            null,
            '1',
            'kg',
        );

        $this->assertMappedRange(
            $this->snapshot(WasteEmissionSnapshot::VERSION, (new WasteEmissionSnapshot())->inputToArray($input)),
            'waste',
            'aceites-usados',
        );
    }

    public function testNullEndDateUsesStartDateAndKeepsNullEmission(): void
    {
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-09-10 18:30:00'),
            null,
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );

        $temporal = $this->mapper->map($this->record(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
            emission: null,
        ));

        self::assertInstanceOf(BgosTemporalRecord::class, $temporal);
        self::assertSame('2026-09-10', $temporal->startDate->format('Y-m-d'));
        self::assertSame('2026-09-10', $temporal->endDate->format('Y-m-d'));
        self::assertNull($temporal->totalKgCo2e);
    }

    public function testSameCalendarDayIsValidRegardlessOfOriginalTimes(): void
    {
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-09-10 23:59:59'),
            new \DateTimeImmutable('2026-09-10 00:00:01'),
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );

        $temporal = $this->mapper->map($this->record(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
        ));

        self::assertInstanceOf(BgosTemporalRecord::class, $temporal);
        self::assertEquals($temporal->startDate, $temporal->endDate);
    }

    public function testReturnsNullForInvalidRange(): void
    {
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-09-12'),
            new \DateTimeImmutable('2026-09-10'),
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );

        self::assertNull($this->mapper->map($this->record(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
        )));
    }

    public function testReturnsNullWhenStartDateIsMissingWithoutUsingRegisteredAt(): void
    {
        $input = new WaterEmissionInput(
            null,
            null,
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );
        $record = $this->record(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
        );
        $record->setRegisteredAt(new \DateTimeImmutable('2026-09-10 18:30:00'));

        self::assertNull($this->mapper->map($record));
    }

    public function testReturnsNullForUnclassifiableRecordOrUnknownPhase(): void
    {
        self::assertNull($this->mapper->map($this->record('{"version":"legacy","input":{}}')));

        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-09-10'),
            null,
            'ESP',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            'sewer',
        );
        self::assertNull($this->mapper->map($this->record(
            $this->snapshot(WaterEmissionSnapshot::VERSION, (new WaterEmissionSnapshot())->inputToArray($input)),
            phaseKey: 'unexpected',
        )));
    }

    private function assertMappedRange(string $snapshot, string $categoryKey, string $subcategoryKey): void
    {
        $temporal = $this->mapper->map($this->record($snapshot));

        self::assertInstanceOf(BgosTemporalRecord::class, $temporal);
        self::assertSame($categoryKey, $temporal->categoryKey);
        self::assertSame($subcategoryKey, $temporal->subcategoryKey);
        self::assertSame('2026-09-10', $temporal->startDate->format('Y-m-d'));
        self::assertSame('2026-09-11', $temporal->endDate->format('Y-m-d'));
    }

    /** @param array<string, mixed> $input */
    private function snapshot(string $version, array $input): string
    {
        return json_encode(['version' => $version, 'input' => $input], JSON_THROW_ON_ERROR);
    }

    private function record(
        string $snapshot,
        string $phaseKey = 'preproduccion',
        ?float $emission = 12.5,
        string $status = EmissionRecord::STATUS_CALCULATED,
    ): EmissionRecord {
        $record = (new EmissionRecord())
            ->setPhase((new ProjectPhaseDate())->setPhase($phaseKey))
            ->setCalculationDetails($snapshot)
            ->setEmission($emission)
            ->setStatus($status);

        $id = new \ReflectionProperty(EmissionRecord::class, 'id');
        $id->setValue($record, 123);

        return $record;
    }
}
