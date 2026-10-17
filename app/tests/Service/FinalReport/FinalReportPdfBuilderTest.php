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
        foreach (glob($this->directory.'/10/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory.'/10');
        @rmdir($this->directory.'/designs');
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
        self::assertSame('Autora', $data['coverPhoto']['author']);
        self::assertSame('Comentario destacado', $data['featuredMeasures'][0]['text']);
        self::assertStringContainsString('Movilidad sostenible', $html);
        self::assertStringContainsString('Comentario destacado', $html);
        self::assertStringContainsString('Pie de portada', $html);
        self::assertStringContainsString('Pie de galería', $html);
        self::assertStringContainsString('2.696,02 kg CO2e', $html);
        self::assertStringContainsString('Energía', $html);
        self::assertStringContainsString('2.280,13 kg CO2e', $html);
        self::assertStringContainsString('Acción trazable registrada', $html);
    }

    private function builder(): FinalReportPdfBuilder
    {
        $twig = $this->twig();
        $components = new ReportDesignComponentCatalog();
        $data = new ReportDesignDataCatalog();
        $designStorage = new ReportDesignStorage($this->directory.'/designs');

        return new FinalReportPdfBuilder(
            new PdfService($twig),
            new ProjectDocumentStorage($this->directory),
            new FinalReportContentEditor(),
            new ReportDesignCatalog(),
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
}
