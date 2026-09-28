<?php

namespace App\Tests\Service\ProjectDocument;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectCatalog;
use App\Enum\ProjectDocumentCatalog;
use App\Form\ProjectDocumentType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProjectDocumentFormTest extends KernelTestCase
{
    public function testLinkRequiresUrl(): void
    {
        self::bootKernel();

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            new ProjectDocument(),
            ['csrf_protection' => false]
        );

        $form->submit([
            'type' => 'script',
            'kind' => ProjectDocumentCatalog::KIND_LINK,
            'otherType' => '',
            'url' => '',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('url')->getErrors(true)->count());
    }

    public function testOtherRequiresFreeText(): void
    {
        self::bootKernel();

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            new ProjectDocument(),
            ['csrf_protection' => false]
        );

        $form->submit([
            'type' => 'other',
            'kind' => ProjectDocumentCatalog::KIND_LINK,
            'otherType' => '',
            'url' => 'https://example.com/documento',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('otherType')->getErrors(true)->count());
    }

    public function testNewFileRequiresUploadedFile(): void
    {
        self::bootKernel();

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            new ProjectDocument(),
            ['csrf_protection' => false]
        );

        $form->submit([
            'type' => 'script',
            'kind' => ProjectDocumentCatalog::KIND_FILE,
            'otherType' => '',
            'url' => '',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('file')->getErrors(true)->count());
    }

    public function testNewFileAcceptsUploadedFile(): void
    {
        self::bootKernel();

        $tmp = tempnam(sys_get_temp_dir(), 'begreen-project-form-');
        file_put_contents($tmp, 'Documento');

        $uploadedFile = new UploadedFile(
            $tmp,
            'documento.txt',
            'text/plain',
            null,
            true
        );

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            new ProjectDocument(),
            ['csrf_protection' => false]
        );

        $form->submit([
            'type' => 'script',
            'kind' => ProjectDocumentCatalog::KIND_FILE,
            'otherType' => '',
            'file' => $uploadedFile,
            'url' => '',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($uploadedFile, $form->get('file')->getData());
    }

    public function testFileWithForbiddenExtensionIsRejectedByForm(): void
    {
        self::bootKernel();

        $tmp = tempnam(sys_get_temp_dir(), 'begreen-project-form-');
        file_put_contents($tmp, 'contenido');

        $uploadedFile = new UploadedFile(
            $tmp,
            'payload.exe',
            'application/octet-stream',
            null,
            true
        );

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            new ProjectDocument(),
            ['csrf_protection' => false]
        );

        $form->submit([
            'type' => 'script',
            'kind' => ProjectDocumentCatalog::KIND_FILE,
            'otherType' => '',
            'file' => $uploadedFile,
            'url' => '',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('file')->getErrors(true)->count());
    }

    public function testAnimationSpecificTypeIsRejectedForNonAnimationProject(): void
    {
        self::bootKernel();

        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre('ficcion');

        $project->addProjectDocument(
            (new ProjectDocument())
                ->setType('animatic')
                ->setKind(ProjectDocumentCatalog::KIND_LINK)
                ->setUrl('https://example.com/animatic')
        );

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($project);

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains('projectDocuments[0].type', $paths);
    }

    public function testPersistedAnimationDocumentIsPreservedWhenProjectStopsBeingAnimation(): void
    {
        self::bootKernel();

        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre('ficcion');

        $document = (new ProjectDocument())
            ->setType('animatic')
            ->setKind(ProjectDocumentCatalog::KIND_LINK)
            ->setUrl('https://example.com/animatic');

        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, 99);
        $project->addProjectDocument($document);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($project);

        foreach ($violations as $violation) {
            self::assertNotSame(
                'projectDocuments[0].type',
                $violation->getPropertyPath(),
                (string) $violation->getMessage()
            );
        }
    }

    public function testPersistedDocumentTypeIsDisabledInForm(): void
    {
        self::bootKernel();

        $document = (new ProjectDocument())
            ->setType('animatic')
            ->setKind(ProjectDocumentCatalog::KIND_LINK)
            ->setUrl('https://example.com/animatic');

        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, 99);

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            $document,
            ['csrf_protection' => false]
        );

        self::assertTrue($form->get('type')->isDisabled());
        self::assertFalse($form->get('type')->getConfig()->getMapped());
        self::assertSame('animatic', $form->get('type')->getData());
    }

    public function testPersistedDocumentKeepsTypeWhenDisabledFieldIsMissingFromPost(): void
    {
        self::bootKernel();

        $document = (new ProjectDocument())
            ->setType('animatic')
            ->setKind(ProjectDocumentCatalog::KIND_LINK)
            ->setUrl('https://example.com/animatic');

        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, 99);

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            ProjectDocumentType::class,
            $document,
            ['csrf_protection' => false]
        );

        // Los campos disabled no se envían en un POST HTML real.
        $form->submit([
            'kind' => ProjectDocumentCatalog::KIND_LINK,
            'otherType' => '',
            'url' => 'https://example.com/animatic',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid());
        self::assertSame('animatic', $document->getType());
        self::assertSame('animatic', $form->get('type')->getData());
    }

    public function testAnimationSpecificTypeIsAllowedForAnimationProject(): void
    {
        self::bootKernel();

        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        $project->addProjectDocument(
            (new ProjectDocument())
                ->setType('animatic')
                ->setKind(ProjectDocumentCatalog::KIND_LINK)
                ->setUrl('https://example.com/animatic')
        );

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($project);

        foreach ($violations as $violation) {
            self::assertNotSame(
                'projectDocuments[0].type',
                $violation->getPropertyPath(),
                (string) $violation->getMessage()
            );
        }
    }
}
