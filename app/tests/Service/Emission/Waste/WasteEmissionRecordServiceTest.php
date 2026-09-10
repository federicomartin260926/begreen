<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\DataFixtures\WasteEmissionFactorFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteEmissionRecordService;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteFactorResolver;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WasteEmissionRecordServiceTest extends TestCase
{
    public function testWritePersistsModernRecordAndRecalculatesExistingRecord(): void
    {
        $input = new WasteEmissionInput(
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2025-03-01'),
            'ESP',
            'Orgánico (residuos de jardín)',
            null,
            'Compostaje',
            '10',
            WasteEmissionInput::UNIT_KG,
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('persist');
        $manager->expects(self::once())->method('flush');

        $project = $this->createMock(Project::class);
        $category = $this->createMock(Category::class);
        $phase = $this->createMock(ProjectPhaseDate::class);
        $record = (new EmissionRecord())->setAmount(999)->setEmission(999);

        $result = (new WasteEmissionRecordService(
            $this->calculator(),
            new WasteEmissionSnapshot(),
            $manager,
        ))->write(
            $project,
            $category,
            $phase,
            $input,
            'Notas',
            $record,
            ['label' => 'Orgánico jardín'],
        );

        self::assertSame($record, $result->record);
        self::assertSame($project, $record->getProject());
        self::assertSame($category, $record->getCategory());
        self::assertSame($phase, $record->getPhase());
        self::assertSame(10.0, $record->getAmount());
        self::assertSame(2.4542, $record->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $record->getStatus());
        self::assertSame('2025-03-01', $record->getRegisteredAt()->format('Y-m-d'));
        self::assertSame('Notas', $record->getNotes());

        $snapshot = json_decode((string) $record->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('waste-v1', $snapshot['version']);
        self::assertSame('10', $snapshot['input']['weight']);
        self::assertSame('2.4542', $snapshot['calculation']['emissionKgCo2e']);
        self::assertSame('Orgánico jardín', $snapshot['presentation']['label']);
    }

    public function testNotAutomaticallyCalculableKeepsKnownAmountAndNullEmission(): void
    {
        $input = new WasteEmissionInput(
            new \DateTimeImmutable('2021-03-01'),
            new \DateTimeImmutable('2021-03-01'),
            'FRA',
            'Residuos domésticos residuales',
            null,
            'Vertedero',
            '100',
            WasteEmissionInput::UNIT_KG,
        );

        $manager = $this->createMock(EntityManagerInterface::class);

        $record = (new WasteEmissionRecordService(
            $this->calculator(),
            new WasteEmissionSnapshot(),
            $manager,
        ))->write(
            $this->createMock(Project::class),
            $this->createMock(Category::class),
            $this->createMock(ProjectPhaseDate::class),
            $input,
        )->record;

        self::assertSame(100.0, $record->getAmount());
        self::assertNull($record->getEmission());
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $record->getStatus());
    }

    public function testPendingCalculationCannotBePersisted(): void
    {
        $input = new WasteEmissionInput(
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2026-03-01'),
            'ESP',
            'Vidrio',
            null,
            null,
            '100',
            WasteEmissionInput::UNIT_KG,
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        (new WasteEmissionRecordService(
            $this->calculator(),
            new WasteEmissionSnapshot(),
            $manager,
        ))->write(
            $this->createMock(Project::class),
            $this->createMock(Category::class),
            $this->createMock(ProjectPhaseDate::class),
            $input,
        );
    }

    private function calculator(): WasteEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $factorManager = $this->createMock(ObjectManager::class);
        $factorManager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new WasteEmissionFactorFixtures($keyGenerator))->load($factorManager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForApplicabilityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool =>
                        'waste' === $categoryKey
                        && $factor->getFunctionalKey() === $functionalKey
                        && null !== $factor->getActivityYear()
                        && $factor->getActivityYear() <= $activityYear
                        && (null === $factor->getYear() || $factor->getYear() <= $activityYear),
                );
                usort(
                    $candidates,
                    static fn (EmissionFactor $left, EmissionFactor $right): int =>
                        $right->getActivityYear() <=> $left->getActivityYear()
                        ?: strcmp((string) $left->getFactorId(), (string) $right->getFactorId()),
                );

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('waste' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new WasteUiCatalog();

        return new WasteEmissionCalculator(
            $catalog,
            new WasteFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog),
        );
    }
}
