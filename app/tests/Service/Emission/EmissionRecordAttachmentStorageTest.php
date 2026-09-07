<?php

namespace App\Tests\Service\Emission;

use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\EmissionRecordAttachmentValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EmissionRecordAttachmentStorageTest extends TestCase
{
    private string $storageDirectory;
    private EmissionRecordAttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir().'/bgfm-emission-attachments-'.bin2hex(random_bytes(8));
        $this->storage = new EmissionRecordAttachmentStorage($this->storageDirectory);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storageDirectory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->storageDirectory);
    }

    public function testStoresPdfPrivatelyWithRandomNameAndMetadata(): void
    {
        $upload = $this->pdfUpload('factura original.pdf');
        $attachment = $this->storage->store($this->record(), $upload);
        $path = $this->storage->absolutePath($attachment);

        self::assertStringStartsWith($this->storageDirectory.'/12/34/', $path);
        self::assertStringNotContainsString('/public/', $path);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $attachment->getStoredName());
        self::assertSame('factura original.pdf', $attachment->getOriginalName());
        self::assertSame('application/pdf', $attachment->getMimeType());
        self::assertGreaterThan(0, $attachment->getSize());
        self::assertFileExists($path);

        $this->storage->delete($attachment);
        self::assertFileDoesNotExist($path);
    }

    public function testRejectsUnsupportedMimeAndOversizedFile(): void
    {
        $textPath = tempnam(sys_get_temp_dir(), 'attachment-text-');
        file_put_contents($textPath, 'plain text');
        $text = new UploadedFile($textPath, 'notes.txt', 'text/plain', null, true);

        try {
            $this->storage->validateUploads([$text]);
            self::fail('Unsupported MIME should be rejected.');
        } catch (EmissionRecordAttachmentValidationException $e) {
            self::assertSame('attachment_type_not_allowed', $e->errorKey);
        }

        $largePath = tempnam(sys_get_temp_dir(), 'attachment-large-');
        $handle = fopen($largePath, 'wb');
        ftruncate($handle, EmissionRecordAttachmentStorage::MAX_FILE_SIZE + 1);
        fclose($handle);
        $large = new UploadedFile($largePath, 'large.pdf', 'application/pdf', null, true);

        $this->expectExceptionObject(new EmissionRecordAttachmentValidationException('attachment_too_large'));
        $this->storage->validateUploads([$large]);
    }

    public function testDeleteAllRemovesEveryPhysicalFile(): void
    {
        $record = $this->record();
        $first = $this->storage->store($record, $this->pdfUpload('one.pdf'));
        $second = $this->storage->store($record, $this->pdfUpload('two.pdf'));
        $firstPath = $this->storage->absolutePath($first);
        $secondPath = $this->storage->absolutePath($second);

        $this->storage->deleteAllForRecord($record);

        self::assertFileDoesNotExist($firstPath);
        self::assertFileDoesNotExist($secondPath);
    }

    public function testRejectsMoreThanFourFilesInOneUpload(): void
    {
        $files = array_map(fn (int $number): UploadedFile => $this->pdfUpload($number.'.pdf'), range(1, 5));

        $this->expectExceptionObject(new EmissionRecordAttachmentValidationException('attachments_too_many'));
        $this->storage->validateUploads($files);
    }

    private function record(): EmissionRecord
    {
        $project = (new Project())->setName('Test')->setType('rodaje')->setCountry('ES');
        $this->setId($project, 12);
        $record = (new EmissionRecord())->setProject($project);
        $this->setId($record, 34);

        return $record;
    }

    private function pdfUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment-pdf-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
