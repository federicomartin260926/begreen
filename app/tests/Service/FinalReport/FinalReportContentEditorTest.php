<?php

declare(strict_types=1);

namespace App\Tests\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use App\Service\FinalReport\FinalReportContentEditor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FinalReportContentEditorTest extends TestCase
{
    private FinalReportContentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new FinalReportContentEditor();
    }

    public function testStoresEditorialContentWithoutChangingMeasureEvidence(): void
    {
        [$project, $plan, $planMeasure, $document] = $this->context();
        $evidence = $planMeasure->getEvidence();
        $content = (new FinalReportContent())->setProject($project);

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => [
                'selected' => '1',
                'text' => 'Resultado especialmente relevante',
                'photoDocumentId' => '20',
            ]],
            'photos' => [20 => [
                'selected' => '1',
                'caption' => 'Equipo reutilizando materiales',
                'author' => 'Producción',
                'planMeasureId' => '10',
                'position' => '2',
            ]],
            'coverDocumentId' => '20',
            'manualTexts' => ['introduction' => 'Introducción editorial'],
        ]);

        self::assertSame([[
            'planMeasureId' => 10,
            'text' => 'Resultado especialmente relevante',
            'origin' => 'manual',
            'photoDocumentId' => 20,
        ]], $content->getFeaturedMeasures());
        self::assertSame([[
            'documentId' => 20,
            'planMeasureId' => 10,
            'caption' => 'Equipo reutilizando materiales',
            'author' => 'Producción',
            'position' => 2,
        ]], $content->getPhotos());
        self::assertSame(20, $content->getCoverDocumentId());
        self::assertSame(['text' => 'Introducción editorial', 'origin' => 'manual'], $content->getManualTexts()['introduction']);
        self::assertSame($evidence, $planMeasure->getEvidence());
        self::assertSame($project, $document->getProject());
    }

    public function testTechnicalAnnexDefaultsToIncludedAndCanBeToggled(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())->setProject($project);
        self::assertTrue($content->isTechnicalAnnexIncluded());

        $this->editor->update($content, $project, $plan, ['includeTechnicalAnnex' => '0']);
        self::assertFalse($content->isTechnicalAnnexIncluded());

        // An older client omitting the setting must not silently reactivate it.
        $this->editor->update($content, $project, $plan, []);
        self::assertFalse($content->isTechnicalAnnexIncluded());

        $this->editor->update($content, $project, $plan, ['includeTechnicalAnnex' => '1']);
        self::assertTrue($content->isTechnicalAnnexIncluded());
    }

    public function testInvalidTechnicalAnnexValueDoesNotPartiallyUpdateContent(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())->setProject($project)->setManualTexts([
            'conclusion' => ['text' => 'Texto anterior', 'origin' => 'manual'],
        ]);
        try {
            $this->editor->update($content, $project, $plan, [
                'includeTechnicalAnnex' => 'invalid',
                'manualTexts' => ['conclusion' => 'Texto nuevo'],
            ]);
            self::fail('Expected an invalid annex preference exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('technical annex', $exception->getMessage());
        }
        self::assertTrue($content->isTechnicalAnnexIncluded());
        self::assertSame('Texto anterior', $content->getManualTexts()['conclusion']['text']);
    }

    public function testRejectsMeasureFromAnotherPlan(): void
    {
        [$project, $plan] = $this->context();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'featured' => [999 => ['selected' => '1']],
        ]);
    }

    public function testFeaturedMeasureCanBeDeselectedOnEditorialSave(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([['planMeasureId' => 10, 'text' => 'Comentario previo']]);

        $this->editor->update($content, $project, $plan, ['featured' => []]);

        self::assertSame([], $content->getFeaturedMeasures());
    }

    public function testNormalizesLegacyFeaturedMeasureAsManual(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())->setFeaturedMeasures([[
            'planMeasureId' => 10,
            'text' => 'Relato anterior',
        ]]);

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => ['selected' => '1', 'text' => 'Relato anterior']],
        ]);

        self::assertSame([[
            'planMeasureId' => 10,
            'text' => 'Relato anterior',
            'origin' => 'manual',
            'photoDocumentId' => null,
        ]], $content->getFeaturedMeasures());
    }

    public function testPreservesExistingFeaturedPhotoWhenFieldIsOmitted(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([[
                'planMeasureId' => 10,
                'text' => 'Relato anterior',
                'photoDocumentId' => 20,
            ]])
            ->setPhotos([['documentId' => 20, 'position' => 1]]);

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => ['selected' => '1', 'text' => 'Relato anterior']],
            'photos' => [20 => ['selected' => '1', 'position' => '1']],
        ]);

        self::assertSame([[
            'planMeasureId' => 10,
            'text' => 'Relato anterior',
            'origin' => 'manual',
            'photoDocumentId' => 20,
        ]], $content->getFeaturedMeasures());
    }

    public function testPreservesFeaturedAiOriginUntilTextIsManuallyChanged(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())->setFeaturedMeasures([[
            'planMeasureId' => 10,
            'text' => 'Relato generado',
            'origin' => 'ai',
            'photoDocumentId' => null,
        ]]);

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => ['selected' => '1', 'text' => 'Relato generado']],
        ]);
        self::assertSame('ai', $content->getFeaturedMeasures()[0]['origin']);

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => ['selected' => '1', 'text' => 'Relato editado']],
        ]);

        self::assertSame('manual', $content->getFeaturedMeasures()[0]['origin']);
        self::assertSame('Relato editado', $content->getFeaturedMeasures()[0]['text']);
    }

    public function testRejectsClientControlledFeaturedOriginWithoutChangingContent(): void
    {
        [$project, $plan] = $this->context();
        $original = [[
            'planMeasureId' => 10,
            'text' => 'Relato manual',
            'origin' => 'manual',
            'photoDocumentId' => null,
        ]];
        $content = (new FinalReportContent())->setProject($project)->setFeaturedMeasures($original);

        try {
            $this->editor->update($content, $project, $plan, [
                'featured' => [10 => [
                    'selected' => '1',
                    'text' => 'Relato manual',
                    'origin' => 'ai',
                ]],
            ]);
            self::fail('Expected a server-controlled origin validation error.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('server-controlled', $exception->getMessage());
        }

        self::assertSame($original, $content->getFeaturedMeasures());
        self::assertSame($project, $content->getProject());
    }

    public function testRejectsFeaturedPhotoThatIsNotSelectedWithoutPartialChanges(): void
    {
        [$project, $plan] = $this->context();
        $originalFeatured = [['planMeasureId' => 10, 'text' => 'Anterior']];
        $originalPhotos = [['documentId' => 20, 'position' => 4]];
        $originalTexts = ['introduction' => ['text' => 'Anterior', 'origin' => 'manual']];
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures($originalFeatured)
            ->setPhotos($originalPhotos)
            ->setManualTexts($originalTexts)
            ->setCoverDocumentId(20);

        try {
            $this->editor->update($content, $project, $plan, [
                'featured' => [10 => [
                    'selected' => '1',
                    'text' => 'Nuevo',
                    'photoDocumentId' => '20',
                ]],
                'manualTexts' => ['introduction' => 'Nueva introducción'],
            ]);
            self::fail('Expected a selected gallery validation error.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('selected report gallery', $exception->getMessage());
        }

        self::assertSame($originalFeatured, $content->getFeaturedMeasures());
        self::assertSame($originalPhotos, $content->getPhotos());
        self::assertSame($originalTexts, $content->getManualTexts());
        self::assertSame(20, $content->getCoverDocumentId());
    }

    public function testRejectsExcludedFeaturedPhoto(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setPhotos([['documentId' => 20, 'excluded' => true]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selected report gallery');

        $this->editor->update($content, $project, $plan, [
            'featured' => [10 => [
                'selected' => '1',
                'photoDocumentId' => '20',
            ]],
            'photos' => [20 => ['selected' => '1', 'position' => '1']],
        ]);
    }

    public function testRejectsUnsupportedFeaturedPhoto(): void
    {
        [$project, $plan] = $this->context();
        $unsupported = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('e', 32).'.pdf')
            ->setMimeType('application/pdf');
        $this->setId($unsupported, 30);
        $project->addProjectDocument($unsupported);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'featured' => [10 => [
                'selected' => '1',
                'photoDocumentId' => '30',
            ]],
            'photos' => [30 => ['selected' => '1', 'position' => '1']],
        ]);
    }

    public function testRejectsFeaturedPhotoFromAnotherProject(): void
    {
        [$project, $plan] = $this->context();
        $foreignProject = (new Project())->setName('Proyecto B');
        $foreignPhoto = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('f', 32).'.jpg')
            ->setMimeType('image/jpeg');
        $this->setId($foreignPhoto, 30);
        $foreignProject->addProjectDocument($foreignPhoto);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selected report gallery');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'featured' => [10 => [
                'selected' => '1',
                'photoDocumentId' => '30',
            ]],
        ]);
    }

    public function testAllowsOneSelectedPhotoForSeveralFeaturedMeasures(): void
    {
        [$project, $plan] = $this->context();
        $secondPlanMeasure = (new PlanMeasure())->setMeasure((new Measure())->setName('Segunda medida'));
        $this->setId($secondPlanMeasure, 11);
        $plan->addPlanMeasure($secondPlanMeasure);
        $content = new FinalReportContent();

        $this->editor->update($content, $project, $plan, [
            'featured' => [
                10 => ['selected' => '1', 'text' => 'Primera', 'photoDocumentId' => '20'],
                11 => ['selected' => '1', 'text' => 'Segunda', 'photoDocumentId' => '20'],
            ],
            'photos' => [20 => ['selected' => '1', 'position' => '1']],
            'coverDocumentId' => '20',
        ]);

        self::assertSame([20, 20], array_column($content->getFeaturedMeasures(), 'photoDocumentId'));
        self::assertSame(20, $content->getCoverDocumentId());
    }

    public function testRejectsDocumentFromAnotherProject(): void
    {
        [$project, $plan] = $this->context();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'photos' => [999 => ['selected' => '1', 'position' => '1']],
        ]);
    }

    public function testCoverMustBelongToSelectedGallery(): void
    {
        [$project, $plan] = $this->context();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cover image');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'coverDocumentId' => '20',
        ]);
    }

    public function testRemovingPhotoOnlyUnlinksEditorialReference(): void
    {
        [$project, $plan, , $document] = $this->context();
        $storedName = $document->getStoredName();
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setPhotos([['documentId' => 20, 'position' => 1]])
            ->setCoverDocumentId(20);

        $this->editor->update($content, $project, $plan, []);

        self::assertSame([], $content->getPhotos());
        self::assertNull($content->getCoverDocumentId());
        self::assertTrue($project->getProjectDocuments()->contains($document));
        self::assertSame($storedName, $document->getStoredName());
    }

    public function testExcludesSelectedPhotoAndClearsCoverWithoutDeletingDocument(): void
    {
        [$project, , , $document] = $this->context();
        $storedName = $document->getStoredName();
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([[
                'planMeasureId' => 10,
                'text' => 'Relato conservado',
                'origin' => 'ai',
                'photoDocumentId' => 20,
            ]])
            ->setPhotos([[
                'documentId' => 20,
                'caption' => 'Pie que se desvincula',
                'position' => 1,
                'replacedDocumentIds' => [19],
            ]])
            ->setCoverDocumentId(20);

        $this->editor->excludePhoto($content, $project, $document);

        self::assertSame([[
            'documentId' => 20,
            'excluded' => true,
            'replacedDocumentIds' => [19],
        ]], $content->getPhotos());
        self::assertSame([20], $this->editor->excludedPhotoDocumentIds($content));
        self::assertSame([19], $this->editor->supersededPhotoDocumentIds($content));
        self::assertNull($content->getCoverDocumentId());
        self::assertTrue($project->getProjectDocuments()->contains($document));
        self::assertSame($storedName, $document->getStoredName());
    }

    public function testExcludesAvailablePhotoAndPreservesExclusionOnEditorialSave(): void
    {
        [$project, $plan, , $document] = $this->context();
        $content = (new FinalReportContent())->setProject($project);

        $this->editor->excludePhoto($content, $project, $document);
        $this->editor->update($content, $project, $plan, [
            'photos' => [20 => [
                'selected' => '1',
                'caption' => 'No debe reactivarse desde el POST',
                'position' => '1',
            ]],
            'coverDocumentId' => '',
        ]);

        self::assertSame([['documentId' => 20, 'excluded' => true]], $content->getPhotos());
        self::assertSame([20], $this->editor->excludedPhotoDocumentIds($content));
        self::assertNull($content->getCoverDocumentId());
    }

    public function testRejectsExcludingPhotoFromAnotherProject(): void
    {
        [$project] = $this->context();
        $foreignProject = (new Project())->setName('Proyecto B');
        $foreign = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('d', 32).'.png')
            ->setMimeType('image/png');
        $this->setId($foreign, 40);
        $foreignProject->addProjectDocument($foreign);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');
        $this->editor->excludePhoto(
            (new FinalReportContent())->setProject($project),
            $project,
            $foreign,
        );
    }

    public function testReplacesPhotoReferenceAndPreservesMetadataAndCover(): void
    {
        [$project, , , $original] = $this->context();
        $replacement = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('b', 32).'.png')
            ->setMimeType('image/png');
        $this->setId($replacement, 21);
        $project->addProjectDocument($replacement);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([[
                'planMeasureId' => 10,
                'text' => 'Relato conservado',
                'origin' => 'ai',
                'photoDocumentId' => 20,
            ]])
            ->setPhotos([[
                'documentId' => 20,
                'planMeasureId' => 10,
                'caption' => 'Pie conservado',
                'author' => 'Autora',
                'position' => 7,
            ]])
            ->setCoverDocumentId(20);

        $this->editor->replacePhoto($content, $project, $original, $replacement);

        self::assertSame([[
            'documentId' => 21,
            'planMeasureId' => 10,
            'caption' => 'Pie conservado',
            'author' => 'Autora',
            'position' => 7,
            'replacedDocumentIds' => [20],
        ]], $content->getPhotos());
        self::assertSame(21, $content->getCoverDocumentId());
        self::assertSame([[
            'planMeasureId' => 10,
            'text' => 'Relato conservado',
            'origin' => 'ai',
            'photoDocumentId' => 21,
        ]], $content->getFeaturedMeasures());
        self::assertTrue($project->getProjectDocuments()->contains($original));
        self::assertSame([20], $this->editor->supersededPhotoDocumentIds($content));
    }

    public function testEditorialSavePreservesReplacementLineage(): void
    {
        [$project, $plan, , $original] = $this->context();
        $replacement = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('b', 32).'.png')
            ->setMimeType('image/png');
        $this->setId($replacement, 21);
        $project->addProjectDocument($replacement);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setPhotos([['documentId' => 20, 'position' => 1]]);
        $this->editor->replacePhoto($content, $project, $original, $replacement);

        $this->editor->update($content, $project, $plan, [
            'photos' => [21 => [
                'selected' => '1',
                'caption' => 'Pie editado',
                'author' => 'Equipo',
                'position' => '3',
            ]],
        ]);

        self::assertSame([20], $content->getPhotos()[0]['replacedDocumentIds']);
        self::assertSame('Pie editado', $content->getPhotos()[0]['caption']);
        self::assertSame([20], $this->editor->supersededPhotoDocumentIds($content));
    }

    public function testExcludingPhotoClearsFeaturedReferencesAndPreservesEditorialData(): void
    {
        [$project, , , $document] = $this->context();
        $featured = [
            [
                'planMeasureId' => 10,
                'text' => 'Relato manual',
                'origin' => 'manual',
                'photoDocumentId' => 20,
            ],
            [
                'planMeasureId' => 11,
                'text' => 'Relato IA',
                'origin' => 'ai',
                'photoDocumentId' => 20,
            ],
        ];
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures($featured)
            ->setPhotos([['documentId' => 20, 'position' => 1]])
            ->setCoverDocumentId(20);

        $this->editor->excludePhoto($content, $project, $document);

        self::assertSame('Relato manual', $content->getFeaturedMeasures()[0]['text']);
        self::assertSame('manual', $content->getFeaturedMeasures()[0]['origin']);
        self::assertNull($content->getFeaturedMeasures()[0]['photoDocumentId']);
        self::assertSame('Relato IA', $content->getFeaturedMeasures()[1]['text']);
        self::assertSame('ai', $content->getFeaturedMeasures()[1]['origin']);
        self::assertNull($content->getFeaturedMeasures()[1]['photoDocumentId']);
        self::assertNull($content->getCoverDocumentId());
    }

    public function testRejectsReplacementOfPhotoFromAnotherProject(): void
    {
        [$project] = $this->context();
        $foreignProject = (new Project())->setName('Proyecto B');
        $foreign = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('c', 32).'.jpg')
            ->setMimeType('image/jpeg');
        $this->setId($foreign, 30);
        $foreignProject->addProjectDocument($foreign);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setPhotos([['documentId' => 30, 'position' => 1]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');
        $this->editor->assertPhotoCanBeReplaced($content, $project, $foreign);
    }

    public function testPreservesAiOriginUntilTextIsManuallyChanged(): void
    {
        [$project, $plan] = $this->context();
        $content = (new FinalReportContent())->setManualTexts([
            'introduction' => ['text' => 'Texto previo', 'origin' => 'ai'],
            'conclusion' => ['text' => 'Cierre previo', 'origin' => 'ai'],
        ]);

        $this->editor->update($content, $project, $plan, [
            'manualTexts' => [
                'introduction' => 'Texto previo',
                'conclusion' => 'Cierre editado manualmente',
            ],
        ]);

        self::assertSame('ai', $content->getManualTexts()['introduction']['origin']);
        self::assertSame('manual', $content->getManualTexts()['conclusion']['origin']);
        self::assertSame('Cierre editado manualmente', $content->getManualTexts()['conclusion']['text']);
    }

    public function testValidatesFinalReportImageFormats(): void
    {
        $pngPath = tempnam(sys_get_temp_dir(), 'final-report-png-');
        $pdfPath = tempnam(sys_get_temp_dir(), 'final-report-pdf-');
        self::assertIsString($pngPath);
        self::assertIsString($pdfPath);
        file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        file_put_contents($pdfPath, "%PDF-1.4\n%%EOF");

        try {
            $this->editor->validateImageUpload(new UploadedFile($pngPath, 'photo.png', 'image/png', null, true));
            self::addToAssertionCount(1);

            $this->expectException(\InvalidArgumentException::class);
            $this->editor->validateImageUpload(new UploadedFile($pdfPath, 'document.pdf', 'application/pdf', null, true));
        } finally {
            @unlink($pngPath);
            @unlink($pdfPath);
        }
    }

    public function testInvalidUploadWithoutTemporaryFileReturnsDomainValidation(): void
    {
        $file = new UploadedFile('', 'too-large.png', 'image/png', UPLOAD_ERR_INI_SIZE, false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backend.final_report.flash.photo_too_large');
        $this->editor->validateImageUpload($file);
    }

    /** @return array{Project, Plan, PlanMeasure, ProjectDocument} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto A')->setType('rodaje')->setCountry('ES');
        $measure = (new Measure())->setName('Medida');
        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setEvidence('/private/evidence.pdf');
        $this->setId($planMeasure, 10);
        $plan = (new Plan())->setProject($project)->addPlanMeasure($planMeasure);
        $document = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName(str_repeat('a', 32).'.jpg')
            ->setMimeType('image/jpeg');
        $this->setId($document, 20);
        $project->addProjectDocument($document);

        return [$project, $plan, $planMeasure, $document];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }
}
