<?php

namespace App\Tests\Service\CrewImport;

use App\Entity\Project;
use App\Entity\User;
use App\Exception\Ai\AiInvalidStructureException;
use App\Service\CrewImport\CrewImportAiBatchInterpreter;
use App\Service\CrewImport\CrewImportAiInterpreterInterface;
use App\Service\CrewImport\CrewImportInterpretedRowsAdapter;
use App\Service\CrewImport\CrewImportProposalBuilder;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;
use App\Service\CrewImport\Dto\CrewImportTabularRow;
use App\Service\CrewImport\Dto\CrewImportTabularSheet;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewImportAiBatchInterpreterTest extends KernelTestCase
{
    public function testSixtyFiveRowsAreSentAsThirtyThirtyAndFive(): void
    {
        $batches = [];
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->expects(self::exactly(3))->method('interpret')
            ->willReturnCallback(function (Project $project, CrewImportTabularDocument $document) use (&$batches): array {
                $batches[] = $document->sheets[0];

                return [];
            });

        self::assertSame([], (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $this->document('People', 65)));
        self::assertSame([30, 30, 5], array_map(static fn (CrewImportTabularSheet $sheet): int => count($sheet->targetRows ?? []), $batches));
        self::assertSame('People!1', $batches[0]->targetRows[0]->sourceReference);
        self::assertSame('People!65', $batches[2]->targetRows[4]->sourceReference);
    }

    public function testSheetsAreNeverMixedAndKeepTheirOrder(): void
    {
        $calls = [];
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->method('interpret')->willReturnCallback(
            function (Project $project, CrewImportTabularDocument $document) use (&$calls): array {
                $sheet = $document->sheets[0];
                $calls[] = [$sheet->name, count($sheet->targetRows ?? [])];

                return [];
            }
        );
        $document = new CrewImportTabularDocument([
            $this->sheet('First', 31),
            $this->sheet('Second', 2),
        ]);

        (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $document);

        self::assertSame([['First', 30], ['First', 1], ['Second', 2]], $calls);
    }

    public function testContextContainsGlobalAndPreviousRowsOutsideTargetLimit(): void
    {
        $batches = [];
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->method('interpret')->willReturnCallback(
            function (Project $project, CrewImportTabularDocument $document) use (&$batches): array {
                $batches[] = $document->sheets[0];

                return [];
            }
        );

        (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $this->document('People', 65));

        self::assertCount(30, $batches[1]->targetRows);
        self::assertSame(
            ['People!1', 'People!2', 'People!3', 'People!4', 'People!5', 'People!28', 'People!29', 'People!30'],
            array_map(static fn (CrewImportTabularRow $row): string => $row->sourceReference, $batches[1]->contextRows),
        );
    }

    public function testValidTargetReferenceAndFewerResultsAreAccepted(): void
    {
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->method('interpret')->willReturn([$this->person('People!2')]);

        $result = (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $this->document('People', 5));

        self::assertCount(1, $result);
        self::assertSame('People!2', $result[0]->sourceReference);
    }

    #[DataProvider('invalidReferenceProvider')]
    public function testContextOnlyAndUnknownReferencesAreRejected(string $reference): void
    {
        $calls = 0;
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->method('interpret')->willReturnCallback(function () use (&$calls, $reference): array {
            ++$calls;

            return $calls === 2 ? [$this->person($reference)] : [];
        });

        $this->expectException(AiInvalidStructureException::class);
        (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $this->document('People', 31));
    }

    public static function invalidReferenceProvider(): iterable
    {
        yield 'context row' => ['People!1'];
        yield 'unknown row' => ['People!999'];
    }

    public function testFailureInSecondBatchAbortsWithoutPartialResult(): void
    {
        $calls = 0;
        $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
        $interpreter->method('interpret')->willReturnCallback(function () use (&$calls): array {
            ++$calls;
            if ($calls === 2) {
                throw new AiInvalidStructureException('Synthetic second batch failure.');
            }

            return [$this->person('People!1')];
        });

        $this->expectException(AiInvalidStructureException::class);
        (new CrewImportAiBatchInterpreter($interpreter))->interpret(new Project(), $this->document('People', 31));
    }

    public function testRepeatedPersonAcrossBatchesIsDeduplicatedBySingleBuilderCall(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get('doctrine')->getConnection();
        self::assertSame('begreenmyfriend_test', $connection->getDatabase());
        $connection->beginTransaction();

        try {
            $entityManager = $container->get(EntityManagerInterface::class);
            $user = (new User())
                ->setName('Batch')
                ->setSurnames('Tester')
                ->setEmail('batch.'.uniqid().'@example.test')
                ->setPassword('password')
                ->setRoles(['ROLE_ADMIN'])
                ->setIsVerified(true);
            $project = (new Project())
                ->setName('Batch project')
                ->setCountry('ES')
                ->setType('rodaje')
                ->setUser($user);
            $entityManager->persist($user);
            $entityManager->persist($project);
            $entityManager->flush();

            $calls = 0;
            $interpreter = $this->createMock(CrewImportAiInterpreterInterface::class);
            $interpreter->method('interpret')->willReturnCallback(function () use (&$calls): array {
                ++$calls;

                return [$this->person($calls === 1 ? 'People!1' : 'People!31')];
            });
            $rows = (new CrewImportAiBatchInterpreter($interpreter))->interpret($project, $this->document('People', 31));
            $extraction = $container->get(CrewImportInterpretedRowsAdapter::class)->toExtraction($rows);
            $proposal = $container->get(CrewImportProposalBuilder::class)->proposal($project, $extraction);

            self::assertCount(1, $proposal->people);
            self::assertSame(['People!1', 'People!31'], $proposal->people[0]->sourceReferences);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function document(string $sheetName, int $rows): CrewImportTabularDocument
    {
        return new CrewImportTabularDocument([$this->sheet($sheetName, $rows)]);
    }

    private function sheet(string $name, int $rows): CrewImportTabularSheet
    {
        return new CrewImportTabularSheet($name, array_map(
            static fn (int $row): CrewImportTabularRow => new CrewImportTabularRow($row, ['value '.$row]),
            range(1, $rows),
        ));
    }

    private function person(string $reference): CrewImportInterpretedRow
    {
        return new CrewImportInterpretedRow(
            $reference,
            'Repeated Person',
            'Repeated',
            'Person',
            'repeated@example.test',
            '',
            '',
            '',
            CrewImportInterpretedRow::CREW,
            null,
            null,
        );
    }
}
