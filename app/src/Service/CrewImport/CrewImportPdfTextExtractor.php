<?php

namespace App\Service\CrewImport;

use App\Exception\CrewImport\CrewImportExtractionException;

final class CrewImportPdfTextExtractor
{
    public const MAX_OUTPUT_BYTES = 200_000;
    public const TIMEOUT_SECONDS = 10.0;

    public function isAvailable(): bool
    {
        $process = @proc_open(['pdftotext', '-v'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }

    public function extract(string $pathname): string
    {
        if (!is_file($pathname) || !is_readable($pathname)) {
            throw new CrewImportExtractionException('pdf_unreadable');
        }
        if (!$this->isAvailable()) {
            throw new CrewImportExtractionException('pdf_runtime_unavailable');
        }

        $process = @proc_open(
            ['pdftotext', '-layout', '-enc', 'UTF-8', $pathname, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new CrewImportExtractionException('pdf_read_failed');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $startedAt = microtime(true);
        do {
            $output .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                proc_terminate($process);
                $this->close($pipes, $process);
                throw new CrewImportExtractionException('pdf_limits');
            }
            $status = proc_get_status($process);
            if (microtime(true) - $startedAt > self::TIMEOUT_SECONDS) {
                proc_terminate($process);
                $this->close($pipes, $process);
                throw new CrewImportExtractionException('pdf_timeout');
            }
            if ($status['running']) {
                usleep(10_000);
            }
        } while ($status['running']);

        $output .= stream_get_contents($pipes[1]);
        $exitCode = $this->close($pipes, $process);
        if ($exitCode !== 0) {
            throw new CrewImportExtractionException('pdf_read_failed');
        }
        $output = trim(preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $output) ?? $output);
        if ($output === '') {
            throw new CrewImportExtractionException('pdf_empty');
        }

        return $output;
    }

    /** @param array<int, resource> $pipes */
    private function close(array $pipes, mixed $process): int
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return proc_close($process);
    }
}
