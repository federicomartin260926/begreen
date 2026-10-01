<?php

namespace App\Service\CrewImport;

use App\Exception\CrewImport\CrewImportProposalCodecException;
use App\Exception\CrewImport\CrewImportProposalStorageException;
use App\Service\CrewImport\Dto\CrewImportProposal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CrewImportProposalStorage
{
    public const DEFAULT_TTL_SECONDS = 7200;
    public const MAX_FILE_BYTES = 4_194_304;

    private const VERSION = 1;
    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/D';

    public function __construct(
        private CrewImportProposalCodec $codec,
        #[Autowire('%kernel.project_dir%/var/storage/crew-import')]
        private string $storageDirectory,
        #[Autowire('%kernel.secret%')]
        private string $secret,
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('Crew import proposal storage requires a secret.');
        }

        if ($this->ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Crew import proposal storage TTL must be positive.');
        }
    }

    public function store(CrewImportProposal $proposal, int $userId, string $sessionId): string
    {
        if ($proposal->projectId <= 0 || $userId <= 0 || $sessionId === '') {
            throw new CrewImportProposalStorageException(
                'invalid_binding',
                'Crew import proposal binding is invalid.'
            );
        }

        $this->ensureDirectory();
        $this->cleanupExpired();

        do {
            $token = bin2hex(random_bytes(32));
            $path = $this->path($token);
        } while (is_file($path));

        $createdAt = new \DateTimeImmutable();
        $expiresAt = $createdAt->modify(sprintf('%+d seconds', $this->ttlSeconds));

        $envelope = [
            'version' => self::VERSION,
            'projectId' => $proposal->projectId,
            'userId' => $userId,
            'sessionHash' => $this->sessionHash($sessionId),
            'createdAt' => $createdAt->format(DATE_ATOM),
            'expiresAt' => $expiresAt->format(DATE_ATOM),
            'proposal' => $this->codec->toArray($proposal),
        ];

        try {
            $json = json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n";
        } catch (\JsonException $exception) {
            throw new CrewImportProposalStorageException(
                'encode_failed',
                'Crew import proposal could not be encoded.',
                $exception
            );
        }

        if (strlen($json) > self::MAX_FILE_BYTES) {
            throw new CrewImportProposalStorageException(
                'too_large',
                'Crew import proposal exceeds the storage size limit.'
            );
        }

        $temporaryPath = tempnam($this->storageDirectory, '.crew-import-');
        if (!is_string($temporaryPath)) {
            throw new CrewImportProposalStorageException(
                'write_failed',
                'Crew import temporary file could not be created.'
            );
        }

        try {
            $written = file_put_contents($temporaryPath, $json, LOCK_EX);
            if ($written !== strlen($json)) {
                throw new \RuntimeException('Incomplete crew import proposal write.');
            }

            if (!@chmod($temporaryPath, 0600)) {
                throw new \RuntimeException('Could not restrict crew import proposal permissions.');
            }

            if (!rename($temporaryPath, $path)) {
                throw new \RuntimeException('Could not atomically store crew import proposal.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);

            throw new CrewImportProposalStorageException(
                'write_failed',
                'Crew import proposal could not be stored.',
                $exception
            );
        }

        return $token;
    }

    public function load(
        string $token,
        int $projectId,
        int $userId,
        string $sessionId,
    ): CrewImportProposal {
        $envelope = $this->readEnvelope($token);

        $this->assertBinding($envelope, $projectId, $userId, $sessionId);

        try {
            /** @var array<string, mixed> $proposalData */
            $proposalData = $envelope['proposal'];
            $proposal = $this->codec->fromArray($proposalData);
        } catch (CrewImportProposalCodecException $exception) {
            throw new CrewImportProposalStorageException(
                'invalid_payload',
                'Stored crew import proposal payload is invalid.',
                $exception
            );
        }

        if ($proposal->projectId !== $projectId) {
            throw new CrewImportProposalStorageException(
                'binding_mismatch',
                'Stored crew import proposal binding does not match.'
            );
        }

        return $proposal;
    }

    public function delete(
        string $token,
        int $projectId,
        int $userId,
        string $sessionId,
    ): void {
        $this->load($token, $projectId, $userId, $sessionId);

        $path = $this->path($token);
        if (is_file($path) && !@unlink($path)) {
            throw new CrewImportProposalStorageException(
                'delete_failed',
                'Crew import proposal could not be deleted.'
            );
        }
    }

    private function readEnvelope(string $token): array
    {
        $this->assertToken($token);
        $path = $this->path($token);

        if (!is_file($path)) {
            throw new CrewImportProposalStorageException(
                'not_found',
                'Crew import proposal was not found.'
            );
        }

        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'invalid_storage',
                'Stored crew import proposal has an invalid size.'
            );
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new CrewImportProposalStorageException(
                'read_failed',
                'Crew import proposal could not be read.'
            );
        }

        try {
            $envelope = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'invalid_storage',
                'Stored crew import proposal JSON is invalid.',
                $exception
            );
        }

        if (
            !is_array($envelope)
            || ($envelope['version'] ?? null) !== self::VERSION
            || !is_int($envelope['projectId'] ?? null)
            || ($envelope['projectId'] ?? 0) <= 0
            || !is_int($envelope['userId'] ?? null)
            || ($envelope['userId'] ?? 0) <= 0
            || !is_string($envelope['sessionHash'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $envelope['sessionHash'])
            || !is_string($envelope['createdAt'] ?? null)
            || !is_string($envelope['expiresAt'] ?? null)
            || !is_array($envelope['proposal'] ?? null)
        ) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'invalid_storage',
                'Stored crew import proposal envelope is invalid.'
            );
        }

        try {
            $createdAt = new \DateTimeImmutable($envelope['createdAt']);
            $expiresAt = new \DateTimeImmutable($envelope['expiresAt']);
        } catch (\Exception $exception) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'invalid_storage',
                'Stored crew import proposal timestamps are invalid.',
                $exception
            );
        }

        if ($expiresAt <= $createdAt) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'invalid_storage',
                'Stored crew import proposal expiration is invalid.'
            );
        }

        if ($expiresAt <= new \DateTimeImmutable()) {
            @unlink($path);
            throw new CrewImportProposalStorageException(
                'expired',
                'Crew import proposal has expired.'
            );
        }

        return $envelope;
    }

    /** @param array<string, mixed> $envelope */
    private function assertBinding(
        array $envelope,
        int $projectId,
        int $userId,
        string $sessionId,
    ): void {
        if ($projectId <= 0 || $userId <= 0 || $sessionId === '') {
            throw new CrewImportProposalStorageException(
                'binding_mismatch',
                'Crew import proposal binding does not match.'
            );
        }

        if (
            $envelope['projectId'] !== $projectId
            || $envelope['userId'] !== $userId
            || !hash_equals(
                (string) $envelope['sessionHash'],
                $this->sessionHash($sessionId)
            )
        ) {
            throw new CrewImportProposalStorageException(
                'binding_mismatch',
                'Crew import proposal binding does not match.'
            );
        }
    }

    private function assertToken(string $token): void
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            throw new CrewImportProposalStorageException(
                'invalid_token',
                'Crew import proposal token is invalid.'
            );
        }
    }

    private function path(string $token): string
    {
        $this->assertToken($token);

        return $this->storageDirectory.'/'.$token.'.json';
    }

    private function sessionHash(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, $this->secret);
    }

    private function ensureDirectory(): void
    {
        if (
            !is_dir($this->storageDirectory)
            && !mkdir($this->storageDirectory, 0700, true)
            && !is_dir($this->storageDirectory)
        ) {
            throw new CrewImportProposalStorageException(
                'write_failed',
                'Crew import proposal directory could not be created.'
            );
        }

        @chmod($this->storageDirectory, 0700);
    }

    private function cleanupExpired(): void
    {
        if (!is_dir($this->storageDirectory)) {
            return;
        }

        $paths = glob($this->storageDirectory.'/*.json');
        if (!is_array($paths)) {
            return;
        }

        $now = new \DateTimeImmutable();

        // Opportunistic cleanup only. Avoid spending an unbounded amount of
        // request time if the directory contains an unexpected number of files.
        foreach (array_slice($paths, 0, 200) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $size = filesize($path);
            if (!is_int($size) || $size <= 0 || $size > self::MAX_FILE_BYTES) {
                @unlink($path);
                continue;
            }

            $json = file_get_contents($path);
            if (!is_string($json)) {
                continue;
            }

            try {
                $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                $expiresAt = is_array($data) && is_string($data['expiresAt'] ?? null)
                    ? new \DateTimeImmutable($data['expiresAt'])
                    : null;
            } catch (\Throwable) {
                @unlink($path);
                continue;
            }

            if (!$expiresAt instanceof \DateTimeImmutable || $expiresAt <= $now) {
                @unlink($path);
            }
        }
    }
}
