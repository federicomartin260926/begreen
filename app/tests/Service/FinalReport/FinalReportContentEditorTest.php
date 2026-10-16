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
            'featured' => [10 => ['selected' => '1', 'text' => 'Resultado especialmente relevante']],
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

        self::assertSame([['planMeasureId' => 10, 'text' => 'Resultado especialmente relevante']], $content->getFeaturedMeasures());
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

    public function testRejectsMeasureFromAnotherPlan(): void
    {
        [$project, $plan] = $this->context();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->editor->update(new FinalReportContent(), $project, $plan, [
            'featured' => [999 => ['selected' => '1']],
        ]);
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
