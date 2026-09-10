<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionReportController;
use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Material\MaterialEmissionInput;
use App\Service\Emission\Material\MaterialEmissionResult;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionResult;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteUiCatalog;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmissionReportControllerTest extends KernelTestCase
{
    public function testOverviewPdfUsesAUnicodeFontForCo2e(): void
    {
        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $pdf = self::getContainer()->get(PdfService::class)->generatePdf(
            'backend/emission/report/overview.html.twig',
            ['project' => $project, 'reportData' => ['Rodaje' => ['Energía' => 2.58]]],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('DejaVuSans', $pdf);
    }

    public function testReportsPresentModernWaterRecordWithoutActivity(): void
    {
        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-01-31'));
        $category = (new Category())->setName('Agua');
        (new \ReflectionClass($category))->getProperty('id')->setValue($category, 5);
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-01-15'),
            new \DateTimeImmutable('2026-01-15'),
            'ES',
            WaterEmissionInput::USE_CLEANING,
            '5',
            WaterEmissionInput::UNIT_CUBIC_METRES,
            WaterEmissionInput::DESTINATION_SEWER,
        );
        $result = new WaterEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '2.5',
            '5',
            'm3',
            2026,
            'annual',
        );
        $waterDetails = json_decode((new WaterEmissionSnapshot())->encode($input, $result), true, 512, JSON_THROW_ON_ERROR);
        $longSource = 'Historical source with a deliberately extensive description to validate deterministic PDF truncation';
        $waterDetails['calculation']['factorTraces'] = [
            [
                'factorId' => 'WAT-REPORT-1',
                'factorActivityYear' => 2026,
                'factorYear' => 2025,
                'factorValue' => '0.5',
                'factorUnit' => 'kg CO2e/m3',
                'source' => $longSource,
                'isFallback' => true,
                'fallbackReason' => 'latest_available_before_activity_year',
                'isGeographicProxy' => true,
                'sourceGeography' => 'GBR',
                'targetGeography' => 'ESP',
            ],
            [
                'factorId' => 'WAT-REPORT-2',
                'factorYear' => 2024,
                'factorValue' => '0.4',
                'source' => 'Fuente histórica B',
            ],
        ];
        $record = (new EmissionRecord())
            ->setProject($project)
            ->setPhase($phase)
            ->setCategory($category)
            ->setAmount(5)
            ->setEmission(2.5)
            ->setCalculationDetails(json_encode($waterDetails, JSON_THROW_ON_ERROR))
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-15'));

        $activeProject = $this->createMock(ActiveProjectService::class);
        $activeProject->method('getActiveProject')->willReturn($project);
        $repository = $this->createMock(EmissionRecordRepository::class);
        $materialCategory = (new Category())->setName('Materiales');
        (new \ReflectionClass($materialCategory))->getProperty('id')->setValue($materialCategory, 7);
        $materialInput = new MaterialEmissionInput(
            activity: 'Madera',
            subproduct: 'Tablero contrachapado',
            family: 'wood',
        );
        $materialResult = new MaterialEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '4.2',
            '3',
            'kg',
            2026,
        );
        $materialRecord = (new EmissionRecord())
            ->setProject($project)
            ->setPhase($phase)
            ->setCategory($materialCategory)
            ->setAmount(3)
            ->setEmission(4.2)
            ->setCalculationDetails((new MaterialEmissionSnapshot())->encode($materialInput, $materialResult))
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-15'));

        $repository->method('findBy')->willReturn([$record, $materialRecord]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $rendered = [];
        $pdf = $this->createMock(PdfService::class);
        $pdf->expects(self::exactly(3))->method('renderPdf')->willReturnCallback(
            static function (string $template, array $data) use (&$rendered): Response {
                $rendered[$template] = $data;

                return new Response('pdf');
            },
        );

        $controller = new EmissionReportController();
        $transportSnapshot = new TransportEmissionSnapshot();
        $energySnapshot = new EnergyEmissionSnapshot();
        $snapshot = new WaterEmissionSnapshot();
        $accommodationSnapshot = new AccommodationEmissionSnapshot();
        $cateringSnapshot = new CateringEmissionSnapshot();
        $wasteSnapshot = new WasteEmissionSnapshot();
        $wasteCatalog = new WasteUiCatalog();
        $materialSnapshot = new MaterialEmissionSnapshot();
        $controller->overview($activeProject, $repository, $pdf, $translator);
        $controller->downloadDetailedReport($activeProject, $repository, $pdf, $translator, $transportSnapshot, $energySnapshot, $snapshot, $accommodationSnapshot, $cateringSnapshot, $wasteSnapshot, $wasteCatalog, $materialSnapshot);
        $controller->emissionsByActivityPdf($activeProject, $repository, $pdf, $translator, $transportSnapshot, $energySnapshot, $snapshot, $accommodationSnapshot, $cateringSnapshot, $wasteSnapshot, $wasteCatalog, $materialSnapshot);

        $activityLabel = 'backend.emission.water_v1.water_use_types.limpieza';
        self::assertSame(2.5, $rendered['backend/emission/report/overview.html.twig']['reportData']['actividad']['Agua']);
        self::assertSame($activityLabel, $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['activity']);
        self::assertSame('backend.emission.water_v1.units.m3', $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['unit']);
        self::assertSame('Tablero contrachapado', $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][1]['activity']);
        self::assertSame('kg', $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][1]['unit']);
        $traceabilities = $rendered['backend/emission/report/detailed.html.twig']['recordTraceabilities'];
        self::assertCount(2, $traceabilities[0]);
        self::assertSame('WAT-REPORT-1', $traceabilities[0][0]['factorId']);
        self::assertSame(2025, $traceabilities[0][0]['factorYear']);
        self::assertSame($longSource, $traceabilities[0][0]['source']);
        self::assertTrue($traceabilities[0][0]['isFallback']);
        self::assertTrue($traceabilities[0][0]['isGeographicProxy']);
        self::assertSame('WAT-REPORT-2', $traceabilities[0][1]['factorId']);
        self::assertSame([], $traceabilities[1]);

        $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['activity'] = str_repeat('A', 70);
        $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['unit'] = str_repeat('U', 40);
        $detailedData = $rendered['backend/emission/report/detailed.html.twig'];
        $html = self::getContainer()->get('twig')->render(
            'backend/emission/report/detailed.html.twig',
            $detailedData,
        );
        self::assertStringContainsString('@page { size: A4 landscape;', $html);
        self::assertSame(9, substr_count($html, '<th>'));
        self::assertStringContainsString('WAT-REPORT-1', $html);
        self::assertStringContainsString('WAT-REPORT-2', $html);
        self::assertStringContainsString(substr($longSource, 0, 70).'…', $html);
        self::assertStringNotContainsString($longSource, $html);
        self::assertStringContainsString(str_repeat('A', 60).'…', $html);
        self::assertStringNotContainsString(str_repeat('A', 70), $html);
        self::assertStringContainsString(str_repeat('U', 32).'…', $html);
        self::assertStringNotContainsString(str_repeat('U', 40), $html);
        self::assertStringContainsString('Fuente histórica B', $html);
        self::assertStringNotContainsString('water-v1', $html);
        $detailedPdf = self::getContainer()->get(PdfService::class)->generatePdf(
            'backend/emission/report/detailed.html.twig',
            $detailedData,
        );
        self::assertStringStartsWith('%PDF-', $detailedPdf);
        self::assertSame(2.5, $rendered['backend/emission/report/by_activity.html.twig']['data'][$activityLabel]['actividad']);
        self::assertSame('Agua', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories'][$activityLabel]);
        self::assertSame(4.2, $rendered['backend/emission/report/by_activity.html.twig']['data']['Tablero contrachapado']['actividad']);
        self::assertSame('Materiales', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories']['Tablero contrachapado']);
    }
}
