<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiReportStorageException;
use App\Service\Ai\Dto\AiStoredReport;
use Psr\Log\LoggerInterface;

final readonly class AiReportStorage
{
    private const MAX_FILE_BYTES = 1_048_576;

    public function __construct(
        private string $storageDirectory,
        private LoggerInterface $logger,
    ) {
    }

    public function read(int $planId, string $locale): ?AiStoredReport
    {
        $path = $this->pathFor($planId, $locale);
        if (!is_file($path)) {
            return null;
        }

        try {
            $size = filesize($path);
            if (!is_int($size) || $size <= 0 || $size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException('Invalid AI report file size.');
            }

            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new \RuntimeException('The AI report file could not be read.');
            }

            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $report = is_array($data) ? AiStoredReport::fromArray($data) : null;
            if (!$report instanceof AiStoredReport) {
                throw new \RuntimeException('Invalid stored AI report structure.');
            }

            return $report;
        } catch (\Throwable $exception) {
            $this->logger->warning('Stored AI report is invalid.', [
                'event' => 'ai_report_storage_invalid',
                'plan_id' => $planId,
                'locale' => $locale,
                'error_type' => $exception::class,
            ]);

            return null;
        }
    }

    public function write(AiStoredReport $report): void
    {
        $path = $this->pathFor($report->planId, $report->locale);
        $directory = dirname($path);
        $temporaryPath = null;

        try {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('The AI report directory could not be created.');
            }

            $json = json_encode(
                $report->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )."\n";
            $temporaryPath = tempnam($directory, '.ai-report-');
            if (!is_string($temporaryPath)) {
                throw new \RuntimeException('The AI report temporary file could not be created.');
            }

            $this->writeTemporaryFile($temporaryPath, $json);

            if (!chmod($temporaryPath, 0600) || !rename($temporaryPath, $path)) {
                throw new \RuntimeException('The AI report file could not be replaced atomically.');
            }

            $temporaryPath = null;
        } catch (\Throwable $exception) {
            $this->logger->error('AI report storage write failed.', [
                'event' => 'ai_report_storage_write_failed',
                'plan_id' => $report->planId,
                'locale' => $report->locale,
                'error_type' => $exception::class,
            ]);

            throw new AiReportStorageException('The AI report could not be stored safely.', previous: $exception);
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function pathFor(int $planId, string $locale): string
    {
        if ($planId <= 0 || !in_array($locale, ['es', 'en'], true)) {
            throw new AiReportStorageException('The AI report storage identity is invalid.');
        }

        return sprintf('%s/%d/%s.json', rtrim($this->storageDirectory, '/'), $planId, $locale);
    }

    private function writeTemporaryFile(string $path, string $contents): void
    {
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('The AI report temporary file could not be opened.');
        }

        $written = 0;
        try {
            $length = strlen($contents);
            while ($written < $length) {
                $bytes = fwrite($handle, substr($contents, $written));
                if (!is_int($bytes) || $bytes <= 0) {
                    throw new \RuntimeException('The AI report temporary file could not be written completely.');
                }
                $written += $bytes;
            }

            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('The AI report temporary file could not be flushed safely.');
            }
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $path);
        if ($written !== strlen($contents) || filesize($path) !== $written) {
            throw new \RuntimeException('The AI report temporary file could not be verified.');
        }
    }
}
