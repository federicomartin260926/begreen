<?php

namespace App\Tests\Form;

use App\Entity\ProjectCompany;
use App\Form\ProjectCompanyType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectCompanyTypeTest extends KernelTestCase
{
    public function testRejectsLogoWithUnsupportedExtension(): void
    {
        $form = $this->createForm();
        $path = $this->createPngFile();

        $form->submit([
            'type' => 'client',
            'name' => 'Empresa',
            'logoFile' => new UploadedFile($path, 'logo.svg', 'image/png', null, true),
        ]);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('PNG, JPG o WebP', (string) $form->get('logoFile')->getErrors(true));
    }

    public function testRejectsLogoLargerThanTwoMegabytes(): void
    {
        $form = $this->createForm();
        $path = $this->createPngFile();
        file_put_contents($path, str_repeat("\0", (2 * 1024 * 1024) + 1), FILE_APPEND);

        $form->submit([
            'type' => 'client',
            'name' => 'Empresa',
            'logoFile' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
        ]);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('2 MB', (string) $form->get('logoFile')->getErrors(true));
    }

    private function createForm(): \Symfony\Component\Form\FormInterface
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);

        return $factory->create(ProjectCompanyType::class, new ProjectCompany());
    }

    private function createPngFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'company-logo-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        return $path;
    }
}
