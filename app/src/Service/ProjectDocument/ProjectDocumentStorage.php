<?php

namespace App\Service\ProjectDocument;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ProjectDocumentStorage
{
    public const MAX_FILE_SIZE = 4 * 1024 * 1024;

    private const STORED_NAME_PATTERN = '/^[a-f0-9]{32}\.(?:pdf|doc|docx|xls|xlsx|ppt|pptx|txt|rtf|csv|jpg|jpeg|png|webp|zip)$/';

    private const EXTENSION_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],
        'txt' => ['text/plain'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/project-documents')]
        private string $storageDirectory,
    ) {
    }

    public function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid() || !is_readable($file->getPathname())) {
            throw new ProjectDocumentValidationException('document_invalid');
        }

        if (($file->getSize() ?: 0) > self::MAX_FILE_SIZE) {
            throw new ProjectDocumentValidationException('document_too_large');
        }

        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ('' === $extension || !isset(self::EXTENSION_MIME_TYPES[$extension])) {
            throw new ProjectDocumentValidationException('document_type_not_allowed');
        }

        $mimeType = $file->getMimeType();
        if (!is_string($mimeType) || !in_array($mimeType, self::EXTENSION_MIME_TYPES[$extension], true)) {
            throw new ProjectDocumentValidationException('document_type_not_allowed');
        }
    }

    public function store(ProjectDocument $document, UploadedFile $file): ProjectDocument
    {
        $this->validateUpload($file);

        $projectId = $document->getProject()?->getId();
        if (null === $projectId) {
            throw new \LogicException('Project documents can only be stored for persisted projects.');
        }

        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mimeType = (string) $file->getMimeType();
        $storedName = bin2hex(random_bytes(16)).'.'.$extension;
        $directory = $this->storageDirectory.'/'.$projectId;
        $path = $directory.'/'.$storedName;

        try {
            $file->move($directory, $storedName);

            $size = filesize($path);
            if (false === $size) {
                throw new \RuntimeException('Unable to determine stored project document size.');
            }

            $document
                ->setOriginalName($file->getClientOriginalName())
                ->setStoredName($storedName)
                ->setMimeType($mimeType)
                ->setSizeBytes($size)
                ->setUrl(null);

            return $document;
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }
            $this->removeEmptyDirectory($directory);

            throw $exception;
        }
    }

    public function absolutePath(ProjectDocument $document): string
    {
        $projectId = $document->getProject()?->getId();
        $storedName = $document->getStoredName();

        if (null === $projectId
            || null === $storedName
            || 1 !== preg_match(self::STORED_NAME_PATTERN, $storedName)) {
            throw new \RuntimeException('Invalid stored project document path.');
        }

        return $this->storageDirectory.'/'.$projectId.'/'.$storedName;
    }

    public function delete(ProjectDocument $document): void
    {
        if (!$document->isFile() || null === $document->getStoredName()) {
            return;
        }

        $projectId = $document->getProject()?->getId();
        if (null === $projectId) {
            throw new \RuntimeException('Invalid project document owner.');
        }

        $this->deleteStoredFile($projectId, $document->getStoredName());
    }

    public function deleteStoredFile(int $projectId, string $storedName): void
    {
        if ($projectId < 1 || 1 !== preg_match(self::STORED_NAME_PATTERN, $storedName)) {
            throw new \RuntimeException('Invalid stored project document path.');
        }

        $path = $this->storageDirectory.'/'.$projectId.'/'.$storedName;

        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to delete project document file.');
        }

        if (file_exists($path)) {
            throw new \RuntimeException('Project document path is not a regular file.');
        }

        $this->removeEmptyDirectory(dirname($path));
    }

    public function deleteAllForProject(Project $project): void
    {
        foreach ($project->getProjectDocuments() as $document) {
            $this->delete($document);
        }
    }

    private function removeEmptyDirectory(string $directory): void
    {
        if (is_dir($directory) && [] === array_diff(scandir($directory) ?: [], ['.', '..'])) {
            rmdir($directory);
        }
    }
}
