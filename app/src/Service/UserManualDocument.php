<?php

declare(strict_types=1);

namespace App\Service;

final readonly class UserManualDocument
{
    public function __construct(
        public string $path,
        public string $downloadName,
        public string $profile,
    ) {
    }
}
