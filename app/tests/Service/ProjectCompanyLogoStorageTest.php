<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectCompany;
use App\Service\ProjectCompanyLogoStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectCompanyLogoStorageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/bgfm-company-logo-' . bin2hex(random_bytes(8));
        mkdir($this->projectDir . '/public', 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->projectDir);
        }
    }

    public function testStoresAndDeletesLogoInsideCompanyDirectory(): void
    {
        $project = new Project();
        $company = new ProjectCompany();
        $this->setId($project, 12);
        $this->setId($company, 34);
        $project->addProjectCompany($company);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'logo-png-');
        file_put_contents($temporaryFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $upload = new UploadedFile($temporaryFile, 'brand.png', 'image/png', null, true);

        $storage = new ProjectCompanyLogoStorage($this->projectDir);
        $path = $storage->store($company, $upload);

        self::assertMatchesRegularExpression('#^/uploads/projects/12/companies/34/logo-[a-f0-9]{32}\.png$#', $path);
        self::assertSame($path, $company->getLogoUrl());
        self::assertTrue($company->hasLogo());
        self::assertFileExists($this->projectDir . '/public' . $path);

        $storage->delete($path);

        self::assertFileDoesNotExist($this->projectDir . '/public' . $path);
        self::assertDirectoryDoesNotExist($this->projectDir . '/public/uploads/projects/12/companies/34');
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
