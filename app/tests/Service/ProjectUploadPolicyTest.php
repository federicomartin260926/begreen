<?php

namespace App\Tests\Service;

use App\Service\ProjectUploadPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProjectUploadPolicyTest extends TestCase
{
    public function testRejectsRequestLargerThanConfiguredMultipartCapacity(): void
    {
        $request = new Request(server: [
            'CONTENT_LENGTH' => (string) (ProjectUploadPolicy::MAX_MULTIPART_REQUEST_SIZE + 1),
        ]);

        self::assertTrue(ProjectUploadPolicy::requestExceedsLimit($request));
    }

    public function testAllowsRequestAtConfiguredMultipartCapacity(): void
    {
        $request = new Request(server: [
            'CONTENT_LENGTH' => (string) ProjectUploadPolicy::MAX_MULTIPART_REQUEST_SIZE,
        ]);

        self::assertFalse(ProjectUploadPolicy::requestExceedsLimit($request));
    }
}
