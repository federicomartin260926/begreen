<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

final class ProjectUploadPolicy
{
    /** Application-specific maximum for project multipart requests (includes all fields and files). */
    public const MAX_MULTIPART_REQUEST_SIZE = 32 * 1024 * 1024;

    public static function requestExceedsLimit(Request $request): bool
    {
        $contentLength = $request->headers->get('Content-Length');

        return is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > self::MAX_MULTIPART_REQUEST_SIZE;
    }
}
