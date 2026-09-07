<?php

namespace App\Service\Emission;

use App\Entity\EmissionRecord;
use App\Entity\EmissionRecordAttachment;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class EmissionRecordAttachmentStorage
{
    public const MAX_FILE_SIZE = 4 * 1024 * 1024;
    public const MAX_FILES_PER_UPLOAD = 4;

    private const MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(private string $storageDirectory)
    {
    }

    /** @param list<UploadedFile> $files */
    public function validateUploads(array $files): void
    {
        if (count($files) > self::MAX_FILES_PER_UPLOAD) {
            throw new EmissionRecordAttachmentValidationException('attachments_too_many');
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || !is_readable($file->getPathname())) {
                throw new EmissionRecordAttachmentValidationException('attachment_invalid');
            }
            if (($file->getSize() ?: 0) > self::MAX_FILE_SIZE) {
                throw new EmissionRecordAttachmentValidationException('attachment_too_large');
            }

            $mimeType = $file->getMimeType();
            if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
                throw new EmissionRecordAttachmentValidationException('attachment_type_not_allowed');
            }

            $clientExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            if ('' !== $clientExtension && !in_array($clientExtension, self::MIME_EXTENSIONS[$mimeType], true)) {
                throw new EmissionRecordAttachmentValidationException('attachment_type_not_allowed');
            }
        }
    }

    public function store(EmissionRecord $record, UploadedFile $file): EmissionRecordAttachment
    {
        $this->validateUploads([$file]);
        $recordId = $record->getId();
        $projectId = $record->getProject()->getId();
        if (null === $recordId || null === $projectId) {
            throw new \LogicException('Attachments can only be stored for persisted records and projects.');
        }

        $mimeType = (string) $file->getMimeType();
        $storedName = bin2hex(random_bytes(16)).'.'.self::MIME_EXTENSIONS[$mimeType][0];
        $directory = $this->storageDirectory.'/'.$projectId.'/'.$recordId;
        $path = $directory.'/'.$storedName;

        try {
            $file->move($directory, $storedName);

            $size = filesize($path);
            if (false === $size) {
                throw new \RuntimeException('Unable to determine stored attachment size.');
            }

            $attachment = (new EmissionRecordAttachment())
                ->setEmissionRecord($record)
                ->setOriginalName($file->getClientOriginalName())
                ->setStoredName($storedName)
                ->setMimeType($mimeType)
                ->setSize($size)
                ->setCreatedAt(new \DateTimeImmutable());
            $record->addAttachment($attachment);

            return $attachment;
        } catch (\Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            $this->removeEmptyDirectories($directory);

            throw $e;
        }
    }

    public function absolutePath(EmissionRecordAttachment $attachment): string
    {
        $record = $attachment->getEmissionRecord();
        if (1 !== preg_match('/^[a-f0-9]{32}\.(?:pdf|jpg|png|webp)$/', $attachment->getStoredName())) {
            throw new \RuntimeException('Invalid stored attachment name.');
        }

        return $this->storageDirectory.'/'.$record->getProject()->getId().'/'.$record->getId().'/'.$attachment->getStoredName();
    }

    public function delete(EmissionRecordAttachment $attachment): void
    {
        $path = $this->absolutePath($attachment);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to delete attachment file.');
        }
        if (file_exists($path)) {
            throw new \RuntimeException('Attachment path is not a regular file.');
        }
        $this->removeEmptyDirectories(dirname($path));
    }

    public function deleteAllForRecord(EmissionRecord $record): void
    {
        foreach ($record->getAttachments() as $attachment) {
            $this->delete($attachment);
        }
    }

    private function removeEmptyDirectories(string $recordDirectory): void
    {
        if (is_dir($recordDirectory) && [] === array_diff(scandir($recordDirectory) ?: [], ['.', '..'])) {
            rmdir($recordDirectory);
        }
    }
}
