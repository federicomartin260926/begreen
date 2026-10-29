<?php

declare(strict_types=1);

namespace App\Service;

/** Tipos de evidencia permitidos. Nunca confiar en el MIME declarado por el navegador. */
final class PlanEvidenceUploadPolicy
{
    private const EXTENSIONS_BY_MIME = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public static function accepts(string $clientExtension, string $detectedMime): bool
    {
        $allowedExtensions = self::EXTENSIONS_BY_MIME[$detectedMime] ?? [];

        return in_array(strtolower($clientExtension), $allowedExtensions, true);
    }
}
