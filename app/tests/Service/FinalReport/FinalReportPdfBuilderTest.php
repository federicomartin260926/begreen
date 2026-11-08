<?php

declare(strict_types=1);

namespace App\Tests\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use App\Service\FinalReport\FinalReportContentEditor;
use App\Service\FinalReport\FinalReportPdfBuilder;
use App\Service\PdfService;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignRenderer;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class FinalReportPdfBuilderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/final-report-pdf-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/10', 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->directory);
    }

    public function testBuildsPdfWithoutAiNarrativeAndEmbedsOnlyOwnedPrivatePhoto(): void
    {
        $project = (new Project())->setName('Informe sin narrativa');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $document = (new ProjectDocument())
            ->setProject($project)->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png')
            ->setMimeType('image/png');
        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, 50);
        $project->addProjectDocument($document);
        file_put_contents($this->directory.'/10/'.$document->getStoredName(), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $content = (new FinalReportContent())->setProject($project)->setPhotos([
            ['documentId' => 50, 'caption' => 'Foto privada', 'author' => 'Equipo', 'position' => 1],
            ['documentId' => 999, 'caption' => 'Ajena', 'position' => 2],
        ])->setCoverDocumentId(50);
        $builder = $this->builder();
        $report = $this->report();

        $data = $builder->buildData($project, $content, $report);
        self::assertCount(1, $data['photos']);
        self::assertSame([], $data['featuredMeasures']);
        self::assertStringStartsWith('data:image/png;base64,', $data['coverPhoto']['dataUri']);
        self::assertSame([], $data['texts']);

        $pdf = $builder->generate($project, $content, $report);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testReplacementPhotoBecomesThePdfCoverImage(): void
    {
        $project = (new Project())->setName('Informe con sustitución');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $original = $this->document($project, 50, str_repeat('a', 32).'.png');
        $replacement = $this->document($project, 51, str_repeat('b', 32).'.png');
        file_put_contents($this->directory.'/10/'.$original->getStoredName(), 'old-image');
        file_put_contents($this->directory.'/10/'.$replacement->getStoredName(), 'new-image');
        $content = (new FinalReportContent())->setProject($project)
            ->setPhotos([['documentId' => 50, 'caption' => 'Conservado', 'position' => 1]])
            ->setCoverDocumentId(50);

        (new FinalReportContentEditor())->replacePhoto($content, $project, $original, $replacement);
        $data = $this->builder()->buildData($project, $content, $this->report());

        self::assertSame(51, $data['coverPhoto']['documentId']);
        self::assertSame('Conservado', $data['coverPhoto']['caption']);
        self::assertSame('new-image', base64_decode(substr(
            $data['coverPhoto']['dataUri'],
            strpos($data['coverPhoto']['dataUri'], ',') + 1,
        ), true));
    }

    public function testExcludedPhotoDoesNotAppearInPdfData(): void
    {
        $project = (new Project())->setName('Informe con exclusión');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $included = $this->document($project, 50, str_repeat('a', 32).'.png');
        $excluded = $this->document($project, 51, str_repeat('b', 32).'.png');
        file_put_contents($this->directory.'/10/'.$included->getStoredName(), 'included-image');
        file_put_contents($this->directory.'/10/'.$excluded->getStoredName(), 'excluded-image');
        $content = (new FinalReportContent())->setProject($project)->setPhotos([
            ['documentId' => 50, 'caption' => 'Visible', 'position' => 1],
            ['documentId' => 51, 'excluded' => true],
        ]);

        $data = $this->builder()->buildData($project, $content, $this->report());

        self::assertCount(1, $data['photos']);
        self::assertSame(50, $data['photos'][0]['documentId']);
        self::assertStringNotContainsString(base64_encode('excluded-image'), $data['photos'][0]['dataUri']);
    }

    public function testPdfRendersFeaturedMeasuresPhotosAndTraceableEmissionBreakdowns(): void
    {
        $project = (new Project())->setName('Informe trazable');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $cover = $this->document($project, 50, str_repeat('a', 32).'.png');
        $gallery = $this->document($project, 51, str_repeat('b', 32).'.png');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        file_put_contents($this->directory.'/10/'.$cover->getStoredName(), $png);
        file_put_contents($this->directory.'/10/'.$gallery->getStoredName(), $png);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([['planMeasureId' => 70, 'text' => 'Comentario destacado']])
            ->setPhotos([
                ['documentId' => 50, 'planMeasureId' => 70, 'caption' => 'Pie de portada', 'author' => 'Autora', 'position' => 1],
                ['documentId' => 51, 'planMeasureId' => null, 'caption' => 'Pie de galería', 'author' => 'Equipo', 'position' => 2],
            ])
            ->setCoverDocumentId(50);
        $report = $this->report();
        $report['plan']['measures'] = [[
            'planMeasureId' => 70,
            'measure' => ['name' => 'Movilidad sostenible'],
        ]];
        $report['implementation'] = [
            'summary' => ['totalMeasures' => 1, 'implementedMeasures' => 1, 'resolvedMeasures' => 1],
            'measures' => [[
                'planMeasureId' => 70,
                'measureName' => 'Movilidad sostenible',
                'state' => 'implemented',
                'actionTaken' => 'Acción trazable registrada',
                'incident' => null,
                'observations' => null,
            ]],
        ];
        $report['emissions'] = [
            'available' => true,
            'validRecordCount' => 2,
            'totalKgCo2e' => 2696.016,
            'byCategory' => [['name' => 'Energía', 'kgCo2e' => 415.887]],
            'byPhase' => [['code' => 'actividad', 'kgCo2e' => 2280.129]],
        ];

        $data = $this->builder()->buildData($project, $content, $report);
        $html = $this->twig()->render('backend/final_report/pdf.html.twig', $data);

        self::assertSame(70, $data['photos'][0]['planMeasureId']);
        self::assertTrue($data['photos'][0]['usedInFeatured']);
        self::assertFalse($data['photos'][1]['usedInFeatured']);
        self::assertSame('Autora', $data['coverPhoto']['author']);
        self::assertSame('Comentario destacado', $data['featuredMeasures'][0]['text']);
        self::assertSame(50, $data['featuredMeasures'][0]['photo']['documentId']);
        self::assertStringContainsString('Movilidad sostenible', $html);
        self::assertStringContainsString('Comentario destacado', $html);
        self::assertSame(2, substr_count($html, 'Pie de portada'));
        self::assertStringNotContainsString('Pie de galería', $html);
        self::assertStringNotContainsString('report-design-final-gallery', $html);
        self::assertStringContainsString('pdf-featured-image-cell', $html);
        self::assertStringContainsString('pdf-featured-story-cell', $html);
        self::assertStringContainsString('2.696,02 kg CO2e', $html);
        self::assertStringContainsString('Energía', $html);
        self::assertStringContainsString('2.280,13 kg CO2e', $html);
        self::assertStringContainsString('Acción trazable registrada', $html);
        self::assertTrue($data['includeTechnicalAnnex']);
        self::assertStringContainsString('<section class="report-design-final-annex">', $html);

        $content->setIncludeTechnicalAnnex(false);
        $withoutAnnexData = $this->builder()->buildData($project, $content, $report);
        $withoutAnnexHtml = $this->twig()->render('backend/final_report/pdf.html.twig', $withoutAnnexData);
        self::assertFalse($withoutAnnexData['includeTechnicalAnnex']);
        self::assertStringNotContainsString('<section class="report-design-final-annex">', $withoutAnnexHtml);
        self::assertStringContainsString('Comentario destacado', $withoutAnnexHtml);
        $content->setIncludeTechnicalAnnex(true);

        $pdf = $this->builder()->generate($project, $content, $report);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testExplicitPhotoWinsAndLegacyFallbackUsesPositionThenDocumentId(): void
    {
        $project = (new Project())->setName('Selección y fallback');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $later = $this->document($project, 50, str_repeat('a', 32).'.png');
        $fallback = $this->document($project, 51, str_repeat('b', 32).'.png');
        $otherMeasureFallback = $this->document($project, 52, str_repeat('c', 32).'.png');
        $fallbackTie = $this->document($project, 53, str_repeat('d', 32).'.png');
        foreach ([$later, $fallback, $otherMeasureFallback, $fallbackTie] as $document) {
            file_put_contents($this->directory.'/10/'.$document->getStoredName(), 'image-'.$document->getId());
        }
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([
                ['planMeasureId' => 70, 'text' => 'Compatibilidad anterior'],
                ['planMeasureId' => 71, 'text' => 'Selección explícita', 'photoDocumentId' => 50],
            ])
            ->setPhotos([
                ['documentId' => 50, 'planMeasureId' => 70, 'caption' => 'Posición dos', 'position' => 2],
                ['documentId' => 51, 'planMeasureId' => 70, 'caption' => 'Posición uno', 'position' => 1],
                ['documentId' => 52, 'planMeasureId' => 71, 'caption' => 'Fallback no elegido', 'position' => 0],
                ['documentId' => 53, 'planMeasureId' => 70, 'caption' => 'Misma posición, ID superior', 'position' => 1],
            ]);
        $report = $this->reportWithMeasures([70, 71]);

        $data = $this->builder()->buildData($project, $content, $report);

        self::assertSame(51, $data['featuredMeasures'][0]['photo']['documentId']);
        self::assertSame(50, $data['featuredMeasures'][1]['photo']['documentId']);
        self::assertSame([52, 51, 53, 50], array_column($data['photos'], 'documentId'));
        self::assertSame([false, true, false, true], array_column($data['photos'], 'usedInFeatured'));
    }

    public function testExplicitMissingOrNullPhotoDoesNotFallbackAndRelatedPhotosRemainStored(): void
    {
        $project = (new Project())->setName('Sin fallback inesperado');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $legacyCandidate = $this->document($project, 50, str_repeat('a', 32).'.png');
        $unreadableExplicit = $this->document($project, 51, str_repeat('b', 32).'.png');
        $nullCandidate = $this->document($project, 52, str_repeat('c', 32).'.png');
        file_put_contents($this->directory.'/10/'.$legacyCandidate->getStoredName(), 'legacy-candidate');
        file_put_contents($this->directory.'/10/'.$nullCandidate->getStoredName(), 'null-candidate');
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([
                ['planMeasureId' => 70, 'text' => 'Explícita ilegible', 'photoDocumentId' => 51],
                ['planMeasureId' => 71, 'text' => 'Sin imagen', 'photoDocumentId' => null],
            ])
            ->setPhotos([
                ['documentId' => 50, 'planMeasureId' => 70, 'caption' => 'Debe seguir en galería', 'position' => 1],
                ['documentId' => 51, 'planMeasureId' => 70, 'caption' => 'Archivo ilegible', 'position' => 2],
                ['documentId' => 52, 'planMeasureId' => 71, 'caption' => 'También sigue en galería', 'position' => 3],
            ]);
        $report = $this->reportWithMeasures([70, 71]);

        $data = $this->builder()->buildData($project, $content, $report);
        $html = $this->twig()->render('backend/final_report/pdf.html.twig', $data);

        self::assertNull($data['featuredMeasures'][0]['photo']);
        self::assertNull($data['featuredMeasures'][1]['photo']);
        self::assertSame([50, 52], array_column($data['photos'], 'documentId'));
        self::assertSame([false, false], array_column($data['photos'], 'usedInFeatured'));
        self::assertSame(['Debe seguir en galería', 'También sigue en galería'], array_column($data['photos'], 'caption'));
        self::assertStringNotContainsString('Debe seguir en galería', $html);
        self::assertStringNotContainsString('También sigue en galería', $html);
        self::assertStringNotContainsString('report-design-final-gallery', $html);
        self::assertStringNotContainsString('Archivo ilegible', $html);
    }

    public function testOneCoverPhotoCanBeReusedBySeveralFeaturedMeasuresWithoutRenderingGallery(): void
    {
        $project = (new Project())->setName('Fotografía compartida');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $shared = $this->document($project, 50, str_repeat('a', 32).'.png');
        $gallery = $this->document($project, 51, str_repeat('b', 32).'.png');
        file_put_contents($this->directory.'/10/'.$shared->getStoredName(), 'shared-image');
        file_put_contents($this->directory.'/10/'.$gallery->getStoredName(), 'gallery-image');
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([
                ['planMeasureId' => 70, 'text' => 'Primer relato', 'photoDocumentId' => 50],
                ['planMeasureId' => 71, 'text' => 'Segundo relato', 'photoDocumentId' => 50],
            ])
            ->setPhotos([
                ['documentId' => 50, 'caption' => 'Crédito compartido', 'author' => 'Equipo', 'position' => 1],
                ['documentId' => 51, 'caption' => 'Galería complementaria', 'position' => 2],
            ])
            ->setCoverDocumentId(50);
        $report = $this->reportWithMeasures([70, 71]);

        $data = $this->builder()->buildData($project, $content, $report);
        $html = $this->twig()->render('backend/final_report/pdf.html.twig', $data);

        self::assertSame([50, 50], array_column(array_column($data['featuredMeasures'], 'photo'), 'documentId'));
        self::assertSame(50, $data['coverPhoto']['documentId']);
        self::assertTrue($data['photos'][0]['usedInFeatured']);
        self::assertSame(3, substr_count($html, 'Crédito compartido'));
        self::assertSame('Galería complementaria', $data['photos'][1]['caption']);
        self::assertSame(0, substr_count($html, 'Galería complementaria'));
        self::assertStringNotContainsString('report-design-final-gallery', $html);
    }

    public function testApprovedDesignKeepsFeaturedAndTechnicalAnnexWithoutGalleryOutput(): void
    {
        $project = (new Project())->setName('Diseño aprobado');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 10);
        $featuredPhoto = $this->document($project, 50, str_repeat('a', 32).'.png');
        $galleryPhoto = $this->document($project, 51, str_repeat('b', 32).'.png');
        file_put_contents($this->directory.'/10/'.$featuredPhoto->getStoredName(), 'featured-image');
        file_put_contents($this->directory.'/10/'.$galleryPhoto->getStoredName(), 'gallery-image');
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([[
                'planMeasureId' => 70,
                'text' => 'Relato en diseño aprobado',
                'photoDocumentId' => 50,
            ]])
            ->setPhotos([
                ['documentId' => 50, 'caption' => 'Foto del componente', 'position' => 1],
                ['documentId' => 51, 'caption' => 'Foto complementaria', 'position' => 2],
            ]);
        $report = $this->reportWithMeasures([70]);
        $report['implementation']['measures'][0]['actionTaken'] = 'Acción conservada en anexo';

        $data = $this->builder(true)->buildData($project, $content, $report);
        $html = $this->twig()->render('backend/final_report/pdf.html.twig', $data);

        self::assertNotNull($data['reportDesignHtml']);
        self::assertStringContainsString('pdf-featured-photo', $data['reportDesignHtml']);
        self::assertSame('Foto complementaria', $data['photos'][1]['caption']);
        self::assertStringNotContainsString('Foto complementaria', $data['reportDesignHtml']);
        self::assertStringNotContainsString('report-design-final-gallery', $data['reportDesignHtml']);
        self::assertStringContainsString('Acción conservada en anexo', $html);
        self::assertStringNotContainsString('report-design-final-gallery', $html);
        self::assertNotFalse(strpos($html, '<section class="report-design-final-annex">'));
        self::assertGreaterThan(
            strpos($html, 'Relato en diseño aprobado'),
            strpos($html, '<section class="report-design-final-annex">'),
        );
    }

    private function builder(bool $approveDesign = false): FinalReportPdfBuilder
    {
        $twig = $this->twig();
        $components = new ReportDesignComponentCatalog();
        $data = new ReportDesignDataCatalog();
        $designStorage = new ReportDesignStorage($this->directory.'/designs');
        $catalog = new ReportDesignCatalog();
        if ($approveDesign) {
            $definition = $catalog->finalSustainabilityReportGeneral();
            $manager = new ReportDesignManager(
                $designStorage,
                new ReportDesignValidator($components, $data),
            );
            $html = implode("\n", array_map(
                static fn (string $key): string => '[COMPONENT:'.$key.']',
                $components->requiredKeys($definition),
            ));
            $manager->saveDraft($definition, $html, '.report-design-final-featured { color: #176b43; }');
            $manager->approve($definition);
        }

        return new FinalReportPdfBuilder(
            new PdfService($twig),
            new ProjectDocumentStorage($this->directory),
            new FinalReportContentEditor(),
            $catalog,
            new ReportDesignRenderer($designStorage, new ReportDesignValidator($components, $data), $components, $data, $twig),
        );
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        return $twig;
    }

    private function document(Project $project, int $id, string $storedName): ProjectDocument
    {
        $document = (new ProjectDocument())
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE)
            ->setStoredName($storedName)
            ->setMimeType('image/png');
        (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($document, $id);
        $project->addProjectDocument($document);

        return $document;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'project' => ['id' => 10, 'name' => 'Informe sin narrativa', 'type' => null, 'mainLocation' => null, 'country' => null],
            'plan' => ['protocol' => null, 'measures' => []],
            'implementation' => ['summary' => ['totalMeasures' => 0, 'implementedMeasures' => 0, 'resolvedMeasures' => 0], 'measures' => []],
            'emissions' => ['available' => false, 'validRecordCount' => 0, 'totalKgCo2e' => null, 'byCategory' => [], 'byPhase' => []],
            'ods' => [],
            'commitment' => [],
        ];
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function reportWithMeasures(array $ids): array
    {
        $report = $this->report();
        $report['plan']['measures'] = array_map(static fn (int $id): array => [
            'planMeasureId' => $id,
            'measure' => ['name' => 'Medida '.$id],
        ], $ids);
        $report['implementation'] = [
            'summary' => [
                'totalMeasures' => count($ids),
                'implementedMeasures' => count($ids),
                'resolvedMeasures' => count($ids),
            ],
            'measures' => array_map(static fn (int $id): array => [
                'planMeasureId' => $id,
                'measureName' => 'Medida '.$id,
                'state' => 'implemented',
                'actionTaken' => null,
                'incident' => null,
                'observations' => null,
            ], $ids),
        ];

        return $report;
    }
}
