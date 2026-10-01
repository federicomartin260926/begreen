<?php

namespace App\Tests\Service\CrewImport;

use App\Exception\CrewImport\CrewImportProposalCodecException;
use App\Exception\CrewImport\CrewImportProposalStorageException;
use App\Service\CrewImport\CrewImportProposalCodec;
use App\Service\CrewImport\CrewImportProposalStorage;
use App\Service\CrewImport\CrewImportWarning;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CrewImportProposalStorageTest extends TestCase
{
    private string $storageDirectory;
    private CrewImportProposalCodec $codec;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir().'/begreen-crew-import-'.bin2hex(random_bytes(8));
        $this->codec = new CrewImportProposalCodec();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDirectory);
    }

    public function testCodecAndStorageRoundTripPreserveProposal(): void
    {
        $proposal = $this->proposal();
        $storage = $this->storage();

        $token = $storage->store($proposal, 45, 'session-one');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertDirectoryExists($this->storageDirectory);

        $files = glob($this->storageDirectory.'/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $storedJson = (string) file_get_contents($files[0]);
        self::assertStringNotContainsString('session-one', $storedJson);
        self::assertStringContainsString('"sessionHash"', $storedJson);

        $loaded = $storage->load($token, 12, 45, 'session-one');
        self::assertSame(['People!27'], $loaded->people[0]->sourceReferences);

        self::assertSame(
            $this->codec->toJson($proposal),
            $this->codec->toJson($loaded)
        );

        $storage->delete($token, 12, 45, 'session-one');
        self::assertFileDoesNotExist($files[0]);
    }

    public function testCodecRejectsManipulatedTypesActionsWarningsAndStatuses(): void
    {
        $data = $this->codec->toArray($this->proposal());

        $invalidAction = $data;
        $invalidAction['people'][0]['action'] = 'anything';
        $this->assertCodecRejects($invalidAction);

        $invalidWarning = $data;
        $invalidWarning['people'][0]['warningCodes'][] = 'UNKNOWN_EXTERNAL_WARNING';
        $this->assertCodecRejects($invalidWarning);

        $invalidStatus = $data;
        $invalidStatus['people'][0]['assignments'][0]['resolutionStatus'] = 'invented';
        $this->assertCodecRejects($invalidStatus);

        $invalidId = $data;
        $invalidId['people'][0]['assignments'][0]['departmentId'] = '10';
        $this->assertCodecRejects($invalidId);

        $legacy = $data;
        unset($legacy['people'][0]['sourceReferences']);
        self::assertSame([], $this->codec->fromArray($legacy)->people[0]->sourceReferences);
    }

    public function testStorageRejectsProjectUserAndSessionMismatches(): void
    {
        $storage = $this->storage();
        $token = $storage->store($this->proposal(), 45, 'session-one');

        $this->assertStorageRejects(
            fn () => $storage->load($token, 999, 45, 'session-one'),
            'binding_mismatch'
        );
        $this->assertStorageRejects(
            fn () => $storage->load($token, 12, 999, 'session-one'),
            'binding_mismatch'
        );
        $this->assertStorageRejects(
            fn () => $storage->load($token, 12, 45, 'other-session'),
            'binding_mismatch'
        );

        // Binding failures do not destroy a still-valid proposal.
        self::assertInstanceOf(
            CrewImportProposal::class,
            $storage->load($token, 12, 45, 'session-one')
        );
    }

    public function testInvalidTokensAndPathTraversalAreRejected(): void
    {
        $storage = $this->storage();

        foreach ([
            '',
            'abc',
            '../secret',
            str_repeat('a', 63),
            str_repeat('a', 65),
            str_repeat('A', 64),
            str_repeat('a', 32).'/'.str_repeat('b', 31),
        ] as $token) {
            $this->assertStorageRejects(
                fn () => $storage->load($token, 12, 45, 'session-one'),
                'invalid_token'
            );
        }
    }

    public function testExpiredProposalIsRejectedAndDeleted(): void
    {
        $storage = $this->storage();
        $token = $storage->store($this->proposal(), 45, 'session-one');

        $files = glob($this->storageDirectory.'/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $envelope = json_decode(
            (string) file_get_contents($files[0]),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($envelope);

        $envelope['createdAt'] = (new \DateTimeImmutable('-3 hours'))->format(DATE_ATOM);
        $envelope['expiresAt'] = (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM);

        file_put_contents(
            $files[0],
            json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n"
        );

        $this->assertStorageRejects(
            fn () => $storage->load($token, 12, 45, 'session-one'),
            'expired'
        );

        self::assertFileDoesNotExist($files[0]);
    }

    public function testDeleteRequiresCorrectBinding(): void
    {
        $storage = $this->storage();
        $token = $storage->store($this->proposal(), 45, 'session-one');

        $this->assertStorageRejects(
            fn () => $storage->delete($token, 12, 45, 'wrong-session'),
            'binding_mismatch'
        );

        self::assertInstanceOf(
            CrewImportProposal::class,
            $storage->load($token, 12, 45, 'session-one')
        );

        $storage->delete($token, 12, 45, 'session-one');

        $this->assertStorageRejects(
            fn () => $storage->load($token, 12, 45, 'session-one'),
            'not_found'
        );
    }

    private function storage(
        int $ttlSeconds = CrewImportProposalStorage::DEFAULT_TTL_SECONDS,
    ): CrewImportProposalStorage {
        return new CrewImportProposalStorage(
            $this->codec,
            $this->storageDirectory,
            'test-secret-that-is-not-a-real-application-secret',
            $ttlSeconds,
        );
    }

    private function proposal(): CrewImportProposal
    {
        return new CrewImportProposal(
            12,
            CrewImportExtraction::OFFICIAL_TEMPLATE,
            [
                new CrewImportPersonProposal(
                    [2, 5],
                    'Ana López',
                    'Ana',
                    'López',
                    'ana@example.test',
                    '600 111 222',
                    null,
                    CrewImportPersonProposal::CREATE,
                    false,
                    [CrewImportWarning::DUPLICATE_IN_FILE],
                    [
                        new CrewImportAssignmentProposal(
                            2,
                            'ARTE',
                            'Director/a de arte',
                            10,
                            20,
                            CrewImportAssignmentProposal::RESOLVED,
                            [],
                        ),
                    ],
                    ['People!27'],
                ),
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function assertCodecRejects(array $data): void
    {
        try {
            $this->codec->fromArray($data);
            self::fail('Invalid proposal payload should be rejected.');
        } catch (CrewImportProposalCodecException) {
            self::assertTrue(true);
        }
    }

    private function assertStorageRejects(\Closure $callback, string $reason): void
    {
        try {
            $callback();
            self::fail(sprintf('Storage operation should fail with "%s".', $reason));
        } catch (CrewImportProposalStorageException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
