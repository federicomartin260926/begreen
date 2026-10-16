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

    private function builder(): FinalReportPdfBuilder
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'));
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
