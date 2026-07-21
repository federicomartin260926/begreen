<?php

namespace App\Service;

use App\Entity\ProjectCompany;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectCompanyLogoStorage
{
    private const PUBLIC_PREFIX = '/uploads/projects';
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    private const MIME_CLIENT_EXTENSIONS = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function store(ProjectCompany $company, UploadedFile $file): string
    {
        $projectId = $company->getProject()?->getId();
        $companyId = $company->getId();
        if ($projectId === null || $companyId === null) {
            throw new \LogicException('Project and company must be persisted before storing a logo.');
        }

        $mimeType = $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
        $clientExtension = strtolower($file->getClientOriginalExtension());
        if ($extension === null || !in_array($clientExtension, self::MIME_CLIENT_EXTENSIONS[$mimeType], true)) {
            throw new FileException('Unsupported company logo MIME type.');
        }

        $directory = $this->companyDirectory($projectId, $companyId);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new FileException('Could not create the company logo directory.');
        }

        try {
            $filename = 'logo-' . bin2hex(random_bytes(16)) . '.' . $extension;
            $file->move($directory, $filename);
        } catch (\Throwable $exception) {
            if ($this->isDirectoryEmpty($directory)) {
                @rmdir($directory);
            }

            throw $exception;
        }

        $path = sprintf('%s/%d/companies/%d/%s', self::PUBLIC_PREFIX, $projectId, $companyId, $filename);
        $company->setLogoPath($path);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path === null || !preg_match('#^/uploads/projects/(\d+)/companies/(\d+)/logo-[a-f0-9]{32}\.(?:png|jpg|webp)$#D', $path, $matches)) {
            return;
        }

        $directory = $this->companyDirectory((int) $matches[1], (int) $matches[2]);
        $absolutePath = $this->publicDirectory() . $path;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        if (is_dir($directory) && $this->isDirectoryEmpty($directory)) {
            @rmdir($directory);
        }
    }

    private function companyDirectory(int $projectId, int $companyId): string
    {
        return sprintf('%s/uploads/projects/%d/companies/%d', $this->publicDirectory(), $projectId, $companyId);
    }

    private function publicDirectory(): string
    {
        return rtrim($this->projectDir, '/') . '/public';
    }

    private function isDirectoryEmpty(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && count($entries) === 2;
    }
}
