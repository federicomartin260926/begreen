<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PlanEvidenceUploadPolicy;
use PHPUnit\Framework\TestCase;

final class PlanEvidenceUploadPolicyTest extends TestCase
{
    public function testAllowsExpectedPairs(): void
    {
        foreach ([
            ['pdf', 'application/pdf'],
            ['PDF', 'application/pdf'],
            ['jpg', 'image/jpeg'],
            ['jpeg', 'image/jpeg'],
            ['png', 'image/png'],
            ['webp', 'image/webp'],
        ] as [$extension, $mime]) {
            self::assertTrue(PlanEvidenceUploadPolicy::accepts($extension, $mime));
        }
    }

    public function testRejectsUnexpectedOrDisguisedContent(): void
    {
        foreach ([
            ['php', 'text/x-php'],
            ['svg', 'image/svg+xml'],
            ['html', 'text/html'],
            ['mkv', 'video/x-matroska'],
            ['pdf', 'text/plain'],
            ['jpg', 'application/pdf'],
            ['', 'application/pdf'],
        ] as [$extension, $mime]) {
            self::assertFalse(PlanEvidenceUploadPolicy::accepts($extension, $mime));
        }
    }
}
