<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\FinalReportController;
use App\Entity\FinalReportContent;
use App\Entity\Measure;
use App\Entity\Ods;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\User;
use App\Exception\Ai\AiTimeoutException;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\FinalReport\FinalReportContentEditor;
use App\Service\FinalReport\FinalReportDataBuilder;
use App\Service\FinalReport\FinalReportNarrativeService;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\SustainabilityCommitmentLevelService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class FinalReportControllerTest extends KernelTestCase
{
    public function testBaseReportIsVisibleWithoutPreviousEditorialContent(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $this->request('backend_final_report_index');
        [$entityManager] = $this->entityManager($project, $plan, null);
        $activeProject = $this->activeProject($project);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $commitment = (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor();
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            $commitment,
        );

        $response = $this->controller()->index(
            $activeProject,
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Informe Base', (string) $response->getContent());
        self::assertStringContainsString('Proyecto final', (string) $response->getContent());
        self::assertStringContainsString('Sin datos disponibles', (string) $response->getContent());
        self::assertStringContainsString('1. Resumen del proyecto', (string) $response->getContent());
        self::assertStringContainsString('featured-measure-search', (string) $response->getContent());
        self::assertStringContainsString('3. Narrativa y textos editoriales', (string) $response->getContent());
        self::assertStringContainsString('final-report-editorial-form', (string) $response->getContent());
    }

    public function testEditorNormalizesPrefixedOdsCodeAndUsesEnglishTranslations(): void
    {
        self::bootKernel();
        $project = $this->project();
        $planMeasure = (new PlanMeasure())
            ->setMeasure((new Measure())->setName('Low-impact transport'))
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setImplemented(true)
            ->setActionTaken(str_repeat('Implemented action with sufficient detail. ', 2))
            ->setEvidence('/private/evidence.pdf')
            ->setOds((new Ods())->setCode('ODS11')->setName('Sustainable cities'));
        (new \ReflectionProperty(PlanMeasure::class, 'id'))->setValue($planMeasure, 10);
        $plan = (new Plan())->setProject($project)->addPlanMeasure($planMeasure);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_index');
        $request->setLocale('en');
        self::getContainer()->get('translator')->setLocale('en');
        [$entityManager] = $this->entityManager($project, $plan, null);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );

        try {
            $response = $this->controller()->index(
                $this->activeProject($project),
                $entityManager,
                $builder,
                new FinalReportContentEditor(),
            );
        } finally {
            self::getContainer()->get('translator')->setLocale('es');
        }

        $html = (string) $response->getContent();
        self::assertStringContainsString('ODS 11 · Sustainable cities', $html);
        self::assertStringNotContainsString('ODS ODS11', $html);
        self::assertStringContainsString('1. Project summary', $html);
        self::assertStringContainsString('Search measure by name', $html);
        self::assertStringContainsString('Implemented', $html);
    }

    public function testReplacementUsesDedicatedMultipartFormWithoutNesting(): void
    {
        self::bootKernel();
        $project = $this->project();
        $planMeasure = (new PlanMeasure())->setMeasure((new Measure())->setName('Transporte sostenible'));
        (new \ReflectionProperty(PlanMeasure::class, 'id'))->setValue($planMeasure, 10);
        $plan = (new Plan())->setProject($project)->addPlanMeasure($planMeasure);
        $document = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 20, 'caption' => 'Imagen', 'position' => 1]])
            ->setFeaturedMeasures([['planMeasureId' => 10, 'text' => 'Comentario conservado']]);
        $this->authenticateAdmin();
        $this->request('backend_final_report_index');
        [$entityManager] = $this->entityManager($project, $plan, $content, $document);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );

        $response = $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );
        $dom = new \DOMDocument();
        @$dom->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($dom);
        $forms = $xpath->query('//form[@action="/backend/final-report/photos/20/replace"]');
        self::assertNotFalse($forms);
        self::assertCount(1, $forms);
        $form = $forms->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame('multipart/form-data', $form->getAttribute('enctype'));
        self::assertSame(1, $xpath->query('.//input[@type="file" and @name="photo"]', $form)?->length);
        self::assertSame(1, $xpath->query('.//input[@type="hidden" and @name="_token"]', $form)?->length);
        self::assertSame(1, $xpath->query('ancestor::article', $form)?->length);
        self::assertSame(0, $xpath->query('ancestor::form', $form)?->length);
        self::assertSame(1, $xpath->query('//input[@id="featured-10" and @checked and @form="final-report-editorial-form"]')?->length);
        self::assertSame('Comentario conservado', trim((string) $xpath->query('//textarea[@id="featured-text-10"]')?->item(0)?->textContent));
        self::assertSame(1, $xpath->query('//form[@action="/backend/final-report/narrative/generate" and @method="post"]')?->length);
    }

    public function testFeaturedMeasuresKeepAllInputsForPaginationAndSelectedFilter(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        for ($id = 1; $id <= 12; ++$id) {
            $planMeasure = (new PlanMeasure())->setMeasure((new Measure())->setName('Medida '.$id));
            (new \ReflectionProperty(PlanMeasure::class, 'id'))->setValue($planMeasure, $id);
            $plan->addPlanMeasure($planMeasure);
        }
        $content = (new FinalReportContent())->setProject($project)->setFeaturedMeasures([
            ['planMeasureId' => 2, 'text' => 'Comentario página uno'],
            ['planMeasureId' => 12, 'text' => 'Comentario página dos'],
        ]);
        $this->authenticateAdmin();
        $this->request('backend_final_report_index');
        [$entityManager] = $this->entityManager($project, $plan, $content);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );

        $response = $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );
        $dom = new \DOMDocument();
        @$dom->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($dom);

        self::assertSame(12, $xpath->query('//*[@data-final-report-editor-target="featuredItem"]')?->length);
        self::assertSame(12, $xpath->query('//input[contains(@name, "featured[") and @form="final-report-editorial-form"]')?->length);
        self::assertSame(2, $xpath->query('//input[@data-final-report-editor-target="featuredCheckbox" and @checked]')?->length);
        self::assertSame('Comentario página uno', trim((string) $xpath->query('//textarea[@id="featured-text-2"]')?->item(0)?->textContent));
        self::assertSame('Comentario página dos', trim((string) $xpath->query('//textarea[@id="featured-text-12"]')?->item(0)?->textContent));
        self::assertSame('2', trim((string) $xpath->query('//*[@data-final-report-editor-target="featuredSelectedCount"]')?->item(0)?->textContent));
        self::assertSame(1, $xpath->query('//input[@id="featured-selected-only" and @data-action="change->final-report-editor#filterSelected"]')?->length);
        self::assertSame(1, $xpath->query('//*[@data-final-report-editor-target="featuredPagination"]')?->length);
    }

    public function testGalleryOmitsOnlyDocumentsExplicitlySupersededByReplacement(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $this->photoDocument($project, 21, str_repeat('b', 32).'.png');
        $this->photoDocument($project, 22, str_repeat('c', 32).'.png');
        $this->photoDocument($project, 23, str_repeat('d', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)->setPhotos([
            [
                'documentId' => 21,
                'caption' => 'Imagen nueva',
                'position' => 1,
                'replacedDocumentIds' => [20],
            ],
            ['documentId' => 23, 'excluded' => true],
        ]);
        $this->authenticateAdmin();
        $this->request('backend_final_report_index');
        [$entityManager] = $this->entityManager($project, $plan, $content);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );

        $response = $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );
        $html = (string) $response->getContent();

        self::assertStringNotContainsString('/backend/final-report/photos/20', $html);
        self::assertStringContainsString('/backend/final-report/photos/21', $html);
        self::assertStringContainsString('/backend/final-report/photos/22', $html);
        self::assertStringNotContainsString('/backend/final-report/photos/23', $html);
        self::assertStringContainsString('/backend/final-report/photos/21/remove', $html);
        self::assertStringContainsString('/backend/final-report/photos/22/remove', $html);
    }

    public function testRemovesSelectedPhotoFromGalleryAndClearsCover(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $document = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 20, 'caption' => 'Pie', 'position' => 1]])
            ->setCoverDocumentId(20);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_remove', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_remove_100_20')->getValue());
        [$entityManager] = $this->entityManager($project, $plan, $content, $document);
        $entityManager->expects(self::once())->method('persist')->with($content);
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller()->removePhoto(
            20,
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([['documentId' => 20, 'excluded' => true]], $content->getPhotos());
        self::assertNull($content->getCoverDocumentId());
        self::assertTrue($project->getProjectDocuments()->contains($document));
    }

    public function testRemovesAvailablePhotoFromGalleryWithoutDeletingDocument(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $document = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_remove', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_remove_100_20')->getValue());
        [$entityManager] = $this->entityManager($project, $plan, null, $document);
        $savedContent = null;
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$savedContent): void {
                $savedContent = $entity;
            },
        );
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller()->removePhoto(
            20,
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertInstanceOf(FinalReportContent::class, $savedContent);
        self::assertSame([['documentId' => 20, 'excluded' => true]], $savedContent->getPhotos());
        self::assertTrue($project->getProjectDocuments()->contains($document));
    }

    public function testPhotoRemovalRejectsDocumentFromAnotherProject(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $foreignProject = (new Project())->setName('Otro proyecto');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($foreignProject, 200);
        $foreign = $this->photoDocument($foreignProject, 20, str_repeat('f', 32).'.png');
        $content = (new FinalReportContent())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_remove', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_remove_100_20')->getValue());
        [$entityManager] = $this->entityManager($project, $plan, $content, $foreign);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $response = $this->controller()->removePhoto(
            20,
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $content->getPhotos());
    }

    public function testManualEditOfAiTextPersistsAndIsRecoveredByNormalReportLoad(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $content = (new FinalReportContent())->setProject($project)->setManualTexts([
            'executive_summary' => ['text' => 'Resumen generado previamente', 'origin' => 'ai'],
        ]);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_save', [], [], 'POST');
        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_save_100')->getValue();
        $request->request->replace([
            '_token' => $token,
            'manualTexts' => ['executive_summary' => 'Resumen editado manualmente y conservado'],
        ]);
        [$entityManager] = $this->entityManager($project, $plan, $content);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (object $entity): bool => $entity instanceof FinalReportContent
                && ($entity->getManualTexts()['executive_summary']['text'] ?? null) === 'Resumen editado manualmente y conservado'
                && ($entity->getManualTexts()['executive_summary']['origin'] ?? null) === 'manual',
        ));
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller()->save(
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/final-report', $response->getTargetUrl());

        $this->request('backend_final_report_index');
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );

        $loaded = $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );

        self::assertSame(200, $loaded->getStatusCode());
        self::assertStringContainsString('Resumen editado manualmente y conservado', (string) $loaded->getContent());
        self::assertSame('manual', $content->getManualTexts()['executive_summary']['origin']);
    }

    public function testSavePersistsFeaturedSelectionsAndCommentsFromDifferentClientPages(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        for ($id = 1; $id <= 12; ++$id) {
            $planMeasure = (new PlanMeasure())->setMeasure((new Measure())->setName('Medida '.$id));
            (new \ReflectionProperty(PlanMeasure::class, 'id'))->setValue($planMeasure, $id);
            $plan->addPlanMeasure($planMeasure);
        }
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_save', [], [], 'POST');
        $request->request->replace([
            '_token' => self::getContainer()->get('security.csrf.token_manager')
                ->getToken('final_report_save_100')->getValue(),
            'featured' => [
                2 => ['selected' => '1', 'text' => 'Comentario página uno'],
                12 => ['selected' => '1', 'text' => 'Comentario página dos'],
            ],
        ]);
        [$entityManager] = $this->entityManager($project, $plan, null);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (object $entity): bool => $entity instanceof FinalReportContent
                && $entity->getFeaturedMeasures() === [
                    ['planMeasureId' => 2, 'text' => 'Comentario página uno'],
                    ['planMeasureId' => 12, 'text' => 'Comentario página dos'],
                ],
        ));
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller()->save(
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
    }

    public function testSaveRejectsInvalidCsrf(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_save', [], ['_token' => 'invalid'], 'POST');
        [$entityManager] = $this->entityManager($project, $plan, null);
        $entityManager->expects(self::never())->method('persist');

        $this->expectException(AccessDeniedException::class);
        $this->controller()->save(
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );
    }

    public function testNarrativeProviderFailureIsNotLoggedTwiceAndKeepsGenericFlash(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $content = (new FinalReportContent())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_narrative_generate', [], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_ai_100')->getValue());
        [$entityManager] = $this->entityManager($project, $plan, $content);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $dataBuilder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor(),
        );
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::once())->method('generate')
            ->willThrowException(new AiTimeoutException('sensitive-provider-detail'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $response = $this->controller()->generateNarrative(
            $request,
            $this->activeProject($project),
            $entityManager,
            $dataBuilder,
            new FinalReportNarrativeService($provider),
            $logger,
            self::getContainer()->get('translator'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            ['backend.final_report.flash.narrative_failed'],
            $request->getSession()->getFlashBag()->peek('danger'),
        );
    }

    public function testUploadsSupportedImageAndAddsItToGallery(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_upload', [], [], 'POST');
        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_upload_100')->getValue();
        $request->request->set('_token', $token);
        $sourcePath = tempnam(sys_get_temp_dir(), 'final-report-upload-');
        self::assertIsString($sourcePath);
        file_put_contents($sourcePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $request->files->set('photo', new UploadedFile($sourcePath, 'report.png', 'image/png', null, true));
        $storageDirectory = sys_get_temp_dir().'/final-report-storage-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory, 0777, true);
        [$entityManager] = $this->entityManager($project, $plan, null);
        $savedContent = null;
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            function (object $entity) use (&$savedContent): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 20);
                }
                if ($entity instanceof FinalReportContent) {
                    $savedContent = $entity;
                }
            },
        );
        $entityManager->expects(self::exactly(2))->method('flush');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        try {
            $response = $this->controller()->uploadPhoto(
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );

            self::assertSame(302, $response->getStatusCode());
            self::assertInstanceOf(FinalReportContent::class, $savedContent);
            self::assertSame(20, $savedContent->getPhotos()[0]['documentId']);
            self::assertSame('final_report_photo', $project->getProjectDocuments()->first()->getType());
            self::assertFileExists($storageDirectory.'/100/'.$project->getProjectDocuments()->first()->getStoredName());
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testPhotoUploadRollsBackWhenSecondFlushFails(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();

        $request = $this->request('backend_final_report_photo_upload', [], [], 'POST');
        $request->request->set(
            '_token',
            self::getContainer()->get('security.csrf.token_manager')
                ->getToken('final_report_upload_100')->getValue()
        );

        $sourcePath = tempnam(sys_get_temp_dir(), 'final-report-rollback-');
        self::assertIsString($sourcePath);
        file_put_contents(
            $sourcePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
        );
        $request->files->set('photo', new UploadedFile($sourcePath, 'rollback.png', 'image/png', null, true));

        $storageDirectory = sys_get_temp_dir().'/final-report-rollback-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory, 0777, true);

        [$entityManager] = $this->entityManager($project, $plan, null);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 21);
                }
            }
        );
        $flushCount = 0;
        $entityManager->expects(self::exactly(2))->method('flush')->willReturnCallback(
            static function () use (&$flushCount): void {
                if (++$flushCount === 2) {
                    throw new \RuntimeException('Simulated second flush failure');
                }
            }
        );

        try {
            $this->controller()->uploadPhoto(
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );
            self::fail('Expected upload failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated second flush failure', $exception->getMessage());
            self::assertSame([], glob($storageDirectory.'/100/*') ?: []);
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testReplacesPhotoAndKeepsMetadataCoverAndOriginalDocument(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $original = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setPhotos([[
                'documentId' => 20,
                'planMeasureId' => null,
                'caption' => 'Pie original',
                'author' => 'Equipo',
                'position' => 4,
            ]])
            ->setCoverDocumentId(20);
        $this->authenticateAdmin();
        [$sourcePath, $upload] = $this->pngUpload('replacement.png', 3 * 1024 * 1024);
        $request = Request::create(
            '/backend/final-report/photos/20/replace',
            'POST',
            [],
            [],
            ['photo' => $upload],
        );
        $this->prepareRequest($request, 'backend_final_report_photo_replace', ['documentId' => 20]);
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_replace_100_20')->getValue());
        $storageDirectory = sys_get_temp_dir().'/final-report-replace-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory.'/100', 0777, true);
        file_put_contents($storageDirectory.'/100/'.$original->getStoredName(), 'original-file');
        [$entityManager] = $this->entityManager($project, $plan, $content, $original);
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 21);
                }
            },
        );
        $entityManager->expects(self::exactly(2))->method('flush');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        try {
            $response = $this->controller()->replacePhoto(
                20,
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );

            self::assertSame(302, $response->getStatusCode());
            self::assertSame([[
                'documentId' => 21,
                'planMeasureId' => null,
                'caption' => 'Pie original',
                'author' => 'Equipo',
                'position' => 4,
                'replacedDocumentIds' => [20],
            ]], $content->getPhotos());
            self::assertSame(21, $content->getCoverDocumentId());
            self::assertTrue($project->getProjectDocuments()->contains($original));
            self::assertFileExists($storageDirectory.'/100/'.$original->getStoredName());
            self::assertCount(2, glob($storageDirectory.'/100/*') ?: []);
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testPhotoReplacementRejectsInvalidFile(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $original = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 20, 'position' => 1]]);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_replace', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_replace_100_20')->getValue());
        $path = tempnam(sys_get_temp_dir(), 'final-report-invalid-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n%%EOF");
        $request->files->set('photo', new UploadedFile($path, 'invalid.pdf', 'application/pdf', null, true));
        [$entityManager] = $this->entityManager($project, $plan, $content, $original);
        $entityManager->expects(self::never())->method('persist');

        try {
            $response = $this->controller()->replacePhoto(
                20,
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage(sys_get_temp_dir().'/unused-final-report-storage'),
                new FinalReportContentEditor(),
            );
            self::assertSame(302, $response->getStatusCode());
            self::assertSame(20, $content->getPhotos()[0]['documentId']);
        } finally {
            @unlink($path);
        }
    }

    public function testPhotoReplacementWithoutFileReturnsValidationMessage(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $original = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 20, 'position' => 1]]);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_replace', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_replace_100_20')->getValue());
        [$entityManager] = $this->entityManager($project, $plan, $content, $original);
        $entityManager->expects(self::never())->method('persist');

        $response = $this->controller()->replacePhoto(
            20,
            $request,
            $this->activeProject($project),
            $entityManager,
            new ProjectDocumentStorage(sys_get_temp_dir().'/unused-final-report-storage'),
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(20, $content->getPhotos()[0]['documentId']);
        self::assertSame(
            ['backend.final_report.flash.photo_required'],
            $request->getSession()->getFlashBag()->peek('danger'),
        );
    }

    public function testPhotoReplacementRejectsDocumentFromAnotherProject(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $foreignProject = (new Project())->setName('Otro proyecto');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($foreignProject, 200);
        $foreign = $this->photoDocument($foreignProject, 20, str_repeat('f', 32).'.png');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 20, 'position' => 1]]);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_replace', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_replace_100_20')->getValue());
        [$sourcePath, $upload] = $this->pngUpload('foreign.png');
        $request->files->set('photo', $upload);
        [$entityManager] = $this->entityManager($project, $plan, $content, $foreign);
        $entityManager->expects(self::never())->method('persist');

        try {
            $response = $this->controller()->replacePhoto(
                20,
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage(sys_get_temp_dir().'/unused-final-report-storage'),
                new FinalReportContentEditor(),
            );
            self::assertSame(302, $response->getStatusCode());
            self::assertSame(20, $content->getPhotos()[0]['documentId']);
        } finally {
            @unlink($sourcePath);
        }
    }

    public function testPhotoReplacementRollbackRestoresOriginalReferenceAndDeletesNewFile(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $original = $this->photoDocument($project, 20, str_repeat('a', 32).'.png');
        $originalPhotos = [['documentId' => 20, 'caption' => 'Conservar', 'position' => 2]];
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos($originalPhotos)->setCoverDocumentId(20);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_replace', ['documentId' => 20], [], 'POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_photo_replace_100_20')->getValue());
        [$sourcePath, $upload] = $this->pngUpload('rollback-replacement.png');
        $request->files->set('photo', $upload);
        $storageDirectory = sys_get_temp_dir().'/final-report-replace-rollback-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory.'/100', 0777, true);
        file_put_contents($storageDirectory.'/100/'.$original->getStoredName(), 'original-file');
        [$entityManager] = $this->entityManager($project, $plan, $content, $original);
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 21);
                }
            },
        );
        $flushCount = 0;
        $entityManager->expects(self::exactly(2))->method('flush')->willReturnCallback(
            static function () use (&$flushCount): void {
                if (++$flushCount === 2) {
                    throw new \RuntimeException('Replacement persistence failed');
                }
            },
        );
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        try {
            $this->controller()->replacePhoto(
                20,
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );
            self::fail('Expected replacement failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Replacement persistence failed', $exception->getMessage());
            self::assertSame($originalPhotos, $content->getPhotos());
            self::assertSame(20, $content->getCoverDocumentId());
            self::assertCount(1, $project->getProjectDocuments());
            self::assertSame([$storageDirectory.'/100/'.$original->getStoredName()], glob($storageDirectory.'/100/*'));
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testIndexRequiresProjectPermission(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->request('backend_final_report_index');
        self::getContainer()->get('security.token_storage')->setToken(null);
        [$entityManager] = $this->entityManager($project, $plan, null);

        $this->expectException(AccessDeniedException::class);
        $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            (new \ReflectionClass(FinalReportDataBuilder::class))->newInstanceWithoutConstructor(),
            new FinalReportContentEditor(),
        );
    }

    private function controller(): FinalReportController
    {
        $controller = new FinalReportController();
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    private function project(): Project
    {
        $project = (new Project())->setName('Proyecto final')->setType('rodaje')->setCountry('ES');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 100);

        return $project;
    }

    private function authenticateAdmin(): void
    {
        $user = (new User())
            ->setEmail('final-report-admin@example.test')
            ->setName('Admin')
            ->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }

    private function request(string $route, array $attributes = [], array $parameters = [], string $method = 'GET'): Request
    {
        $request = new Request([], $parameters, $attributes, [], [], ['REQUEST_METHOD' => $method]);
        $this->prepareRequest($request, $route, $attributes);

        return $request;
    }

    /** @param array<string, mixed> $attributes */
    private function prepareRequest(Request $request, string $route, array $attributes = []): void
    {
        $request->attributes->add($attributes);
        $request->attributes->set('_route', $route);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);
    }

    private function activeProject(Project $project): ActiveProjectService&MockObject
    {
        $service = $this->createMock(ActiveProjectService::class);
        $service->method('getActiveProject')->willReturn($project);

        return $service;
    }

    /** @return array{EntityManagerInterface&MockObject} */
    private function entityManager(
        Project $project,
        Plan $plan,
        ?FinalReportContent $content,
        ?ProjectDocument $document = null,
    ): array
    {
        $planRepository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $planRepository->method('findOneBy')->with(['project' => $project])->willReturn($plan);
        $contentRepository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contentRepository->method('findOneBy')->with(['project' => $project])->willReturn($content);
        $documentRepository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $documentRepository->method('find')->willReturn($document);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                Plan::class => $planRepository,
                FinalReportContent::class => $contentRepository,
                ProjectDocument::class => $documentRepository,
                default => throw new \LogicException('Unexpected repository '.$class),
            },
        );

        return [$entityManager];
    }

    private function photoDocument(Project $project, int $id, string $storedName): ProjectDocument
    {
        $document = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(\App\Enum\ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName($storedName)
            ->setMimeType('image/png');
        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, $id);
        $project->addProjectDocument($document);

        return $document;
    }

    /** @return array{string, UploadedFile} */
    private function pngUpload(string $name, ?int $size = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'final-report-replacement-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        if ($size !== null) {
            $handle = fopen($path, 'ab');
            self::assertIsResource($handle);
            ftruncate($handle, $size);
            fclose($handle);
        }

        return [$path, new UploadedFile($path, $name, 'image/png', null, true)];
    }
}
