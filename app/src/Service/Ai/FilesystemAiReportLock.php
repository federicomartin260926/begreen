<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiReportStorageException;

final readonly class FilesystemAiReportLock implements AiReportLockInterface
{
    public function __construct(
        private string $lockDirectory,
        private int $waitSeconds,
    ) {
    }

    public function synchronized(string $key, callable $callback): mixed
    {
        if (!is_dir($this->lockDirectory) && !mkdir($this->lockDirectory, 0770, true) && !is_dir($this->lockDirectory)) {
            throw new AiReportStorageException('The AI report lock could not be acquired safely.');
        }

        $path = sprintf('%s/%s.lock', rtrim($this->lockDirectory, '/'), hash('sha256', $key));
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new AiReportStorageException('The AI report lock could not be acquired safely.');
        }

        $deadline = microtime(true) + max(1, $this->waitSeconds);
        $locked = false;

        try {
            do {
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    usleep(50_000);
                }
            } while (!$locked && microtime(true) < $deadline);

            if (!$locked) {
                throw new AiReportStorageException('The AI report lock timed out.');
            }

            return $callback();
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
}
