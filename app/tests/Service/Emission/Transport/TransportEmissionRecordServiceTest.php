<?php

namespace App\Tests\Service\Emission\Transport;

use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Transport\TransportEmissionCalculator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionRecordService;
use App\Service\Emission\Transport\TransportEmissionRequestMapper;
use App\Service\Emission\Transport\TransportEmissionResult;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TransportEmissionRecordServiceTest extends TestCase
{
    public function testCalculatedResultCreatesModernRecordAndBackendSnapshot(): void
    {
        [$service, $snapshot] = $this->service($this->factor('0.5'));

        $result = $service->write(...$this->writeArguments($this->carInput()));

        self::assertTrue($result->isPersisted());
        self::assertSame(10.0, $result->record?->getAmount());
        self::assertSame(5.0, $result->record?->getEmission());
        self::assertSame('Transporte', $result->record?->getCategory()?->getName());
        self::assertSame('2025-06-01', $result->record?->getRegisteredAt()->format('Y-m-d'));
        self::assertSame('Nota', $result->record?->getNotes());
        self::assertTrue($snapshot->isTransportV20($result->record?->getCalculationDetails()));
        $snapshotData = json_decode((string) $result->record?->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('10', $snapshotData['calculation']['normalizedActivityValue']);
        self::assertSame('2025-06-01', $snapshotData['input']['startDate']);
        self::assertSame('2025-06-01', $snapshotData['input']['endDate']);
        self::assertArrayNotHasKey('startedAt', $snapshotData['input']);
    }

    public function testDirectZeroAndOperatorArePersistedIncludingZero(): void
    {
        [$zeroService] = $this->service(null, persistCalls: 1);
        $zeroInput = new TransportEmissionInput('local', 'walk', 'distance', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '3', 'km');
        $zero = $zeroService->write(...$this->writeArguments($zeroInput));
        self::assertSame(TransportEmissionResult::STATUS_DIRECT_ZERO, $zero->calculation->status);
        self::assertSame(0.0, $zero->record?->getEmission());

        [$operatorService] = $this->service(null, persistCalls: 1);
        $operatorInput = new TransportEmissionInput('local', 'taxi', 'operator', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '2', 'kg_co2e', '3');
        $operator = $operatorService->write(...$this->writeArguments($operatorInput));
        self::assertSame(TransportEmissionResult::STATUS_DIRECT_OPERATOR_EMISSION, $operator->calculation->status);
        self::assertSame(6.0, $operator->record?->getEmission());
    }

    public function testFactorNotAvailableIsNotPersisted(): void
    {
        [$service] = $this->service(null, persistCalls: 0);

        $result = $service->write(...$this->writeArguments($this->carInput()));

        self::assertFalse($result->isPersisted());
        self::assertSame(TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE, $result->calculation->status);
    }

    public function testExternalFactorRequiredIsNotPersisted(): void
    {
        [$service] = $this->service(null, persistCalls: 0);
        $input = new TransportEmissionInput('local', 'car', 'electricity', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '10', 'kWh', vehicleType: 'bev');

        $result = $service->write(...$this->writeArguments($input));

        self::assertFalse($result->isPersisted());
        self::assertSame(TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED, $result->calculation->status);
    }

    public function testUnsupportedResultIsNotPersisted(): void
    {
        [$service] = $this->service(null, persistCalls: 0);
        $input = new TransportEmissionInput('local', 'car', 'fuel', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '10', 'l', vehicleType: 'bev', fuel: 'petrol');

        $result = $service->write(...$this->writeArguments($input));

        self::assertFalse($result->isPersisted());
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $result->calculation->status);
    }

    public function testExplicitNullFactorIsNotPersisted(): void
    {
        [$service] = $this->service($this->factor(null), persistCalls: 0);

        $result = $service->write(...$this->writeArguments($this->carInput()));

        self::assertFalse($result->isPersisted());
        self::assertSame(TransportEmissionResult::STATUS_EXPLICIT_NULL_FACTOR, $result->calculation->status);
    }

    public function testRequestCannotOverrideBackendAmountsFactorOrEmission(): void
    {
        $request = Request::create('/', 'POST', [
            'category' => 'local', 'mode' => 'car', 'method' => 'distance', 'country' => 'es',
            'startDate' => '2025-06-01', 'endDate' => '2025-06-01', 'activityValue' => '10', 'activityUnit' => 'km',
            'vehicleType' => 'petrol', 'factorValue' => '999', 'factorYear' => '1900',
            'source' => 'browser', 'functionalKey' => 'browser', 'amount' => '999',
            'emission' => '999', 'generatedKgCo2e' => '999',
        ]);
        $input = (new TransportEmissionRequestMapper())->map($request);
        [$service] = $this->service($this->factor('0.5'));

        $result = $service->write(...$this->writeArguments($input));

        self::assertSame(10.0, $result->record?->getAmount());
        self::assertSame(5.0, $result->record?->getEmission());
        $data = json_decode((string) $result->record?->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.5', $data['factor']['value']);
        self::assertSame('MITECO', $data['factor']['source']);
        self::assertArrayNotHasKey('emission', $data['input']);
        self::assertArrayNotHasKey('factorValue', $data['input']);
    }

    public function testRequestMapperAcceptsSameDayAndCrossYearRanges(): void
    {
        $mapper = new TransportEmissionRequestMapper();
        $base = [
            'category' => 'local', 'mode' => 'walk', 'method' => 'distance', 'country' => 'ES',
            'activityValue' => '2', 'activityUnit' => 'km',
        ];

        $sameDay = $mapper->map(Request::create('/', 'POST', $base + [
            'startDate' => '2026-06-01', 'endDate' => '2026-06-01',
        ]));
        $crossYear = $mapper->map(Request::create('/', 'POST', $base + [
            'startDate' => '2026-12-30', 'endDate' => '2027-01-02',
        ]));

        self::assertSame('2026-06-01', $sameDay->startDate->format('Y-m-d'));
        self::assertSame('2026-06-01', $sameDay->endDate->format('Y-m-d'));
        self::assertSame('2026-12-30', $crossYear->startDate->format('Y-m-d'));
        self::assertSame('2027-01-02', $crossYear->endDate->format('Y-m-d'));
    }

    public function testEditingRecalculatesWithCurrentResolverInsteadOfStoredFactor(): void
    {
        $input = $this->carInput();
        $storedResult = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            '10',
            'km',
            '9990',
            ['area' => 'ESPAÑA'],
            'stored-key',
            2025,
            2025,
            '999',
            'km',
            'browser',
        );
        $record = (new EmissionRecord())->setCalculationDetails((new TransportEmissionSnapshot())->encode($input, $storedResult));
        [$service] = $this->service($this->factor('0.25'));
        $arguments = $this->writeArguments($input);
        $arguments['record'] = $record;

        $result = $service->write(...$arguments);

        self::assertSame($record, $result->record);
        self::assertSame(2.5, $record->getEmission());
        self::assertSame('0.25', json_decode((string) $record->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR)['factor']['value']);
    }

    public function testPresentationIsPersistedWithoutChangingCalculation(): void
    {
        [$service] = $this->service($this->factor('0.5'));
        $arguments = $this->writeArguments($this->carInput());
        $arguments['presentation'] = ['origin' => 'Madrid', 'destination' => 'Toledo'];

        $result = $service->write(...$arguments);
        $snapshot = json_decode((string) $result->record?->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5.0, $result->record?->getEmission());
        self::assertSame('10', $snapshot['calculation']['normalizedActivityValue']);
        self::assertSame(['origin' => 'Madrid', 'destination' => 'Toledo'], $snapshot['presentation']);
    }

    /** @return array{0: TransportEmissionRecordService, 1: TransportEmissionSnapshot} */
    private function service(?EmissionFactor $factor, int $persistCalls = 1): array
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturn($factor);
        $keyGenerator = new EmissionFactorKeyGenerator();
        $calculator = new TransportEmissionCalculator(
            new TransportFactorCriteriaMapper(),
            new EmissionFactorResolver($repository, $keyGenerator),
            $keyGenerator,
        );
        $snapshot = new TransportEmissionSnapshot();
        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist');
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return [new TransportEmissionRecordService($calculator, $snapshot, $entityManager), $snapshot];
    }

    private function factor(?string $value): EmissionFactor
    {
        return (new EmissionFactor())
            ->setCategoryKey('transport')->setFunctionalKey('server-key')->setCriteria([])
            ->setYear(2025)->setValue($value)->setUnit('km')->setSource('MITECO')->setSourceDetail('Backend');
    }

    private function carInput(): TransportEmissionInput
    {
        return new TransportEmissionInput(
            'local', 'car', 'distance', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '10', 'km', vehicleType: 'petrol',
        );
    }

    /** @return array<string, mixed> */
    private function writeArguments(TransportEmissionInput $input): array
    {
        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $category = (new Category())->setName('Transporte');
        $phase = (new ProjectPhaseDate())
            ->setProject($project)->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2025-01-01'))->setEndDate(new \DateTimeImmutable('2025-12-31'));

        return ['project' => $project, 'category' => $category, 'phase' => $phase, 'input' => $input, 'notes' => 'Nota'];
    }
}
