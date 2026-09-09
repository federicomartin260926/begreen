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
        $record = (new EmissionRecord())
            ->setProject($project)
            ->setPhase($phase)
            ->setCategory($category)
            ->setAmount(5)
            ->setEmission(2.5)
            ->setCalculationDetails((new WaterEmissionSnapshot())->encode($input, $result))
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
        self::assertSame(2.5, $rendered['backend/emission/report/by_activity.html.twig']['data'][$activityLabel]['actividad']);
        self::assertSame('Agua', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories'][$activityLabel]);
        self::assertSame(4.2, $rendered['backend/emission/report/by_activity.html.twig']['data']['Tablero contrachapado']['actividad']);
        self::assertSame('Materiales', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories']['Tablero contrachapado']);
    }
}
