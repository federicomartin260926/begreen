<?php

namespace App\Tests\Service\ProjectDocument;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\ProjectDocument\ProjectDocumentValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectDocumentStorageTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir().'/begreen-project-documents-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->storageDirectory,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->storageDirectory);
        }
    }

    public function testStoresAllowedFilePrivatelyWithRandomName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'begreen-doc-');
        file_put_contents($tmp, 'Documento de prueba');

        $file = new UploadedFile($tmp, 'documento.txt', 'text/plain', null, true);

        $project = new Project();
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 123);

        $document = (new ProjectDocument())
            ->setProject($project)
            ->setType('script')
            ->setKind(ProjectDocumentCatalog::KIND_FILE);

        $storage = new ProjectDocumentStorage($this->storageDirectory);
        $storage->store($document, $file);

        self::assertSame('documento.txt', $document->getOriginalName());
        self::assertSame('text/plain', $document->getMimeType());
        self::assertNotNull($document->getSizeBytes());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.txt$/', (string) $document->getStoredName());
        self::assertFileExists($storage->absolutePath($document));

        $storage->delete($document);
        self::assertFileDoesNotExist(
            $this->storageDirectory.'/123/'.$document->getStoredName()
        );
    }

    public function testRejectsDisallowedExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'begreen-doc-');
        file_put_contents($tmp, 'contenido');

        $file = new UploadedFile($tmp, 'payload.exe', 'application/octet-stream', null, true);

        $this->expectException(ProjectDocumentValidationException::class);
        $this->expectExceptionMessage('document_type_not_allowed');

        (new ProjectDocumentStorage($this->storageDirectory))->validateUpload($file);
    }

    public function testRejectsFileLargerThanLimit(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'begreen-doc-');
        $handle = fopen($tmp, 'wb');
        fseek($handle, ProjectDocumentStorage::MAX_FILE_SIZE);
        fwrite($handle, 'x');
        fclose($handle);

        $file = new UploadedFile($tmp, 'large.txt', 'text/plain', null, true);

        $this->expectException(ProjectDocumentValidationException::class);
        $this->expectExceptionMessage('document_too_large');

        (new ProjectDocumentStorage($this->storageDirectory))->validateUpload($file);
    }
}
