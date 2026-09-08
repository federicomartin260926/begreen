<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionReportController;
use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionResult;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\PdfService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmissionReportControllerTest extends TestCase
{
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
        $repository->method('findBy')->willReturn([$record]);
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
        $snapshot = new WaterEmissionSnapshot();
        $controller->overview($activeProject, $repository, $pdf, $translator);
        $controller->downloadDetailedReport($activeProject, $repository, $pdf, $translator, $snapshot);
        $controller->emissionsByActivityPdf($activeProject, $repository, $pdf, $translator, $snapshot);

        $activityLabel = 'backend.emission.water_v1.water_use_types.limpieza';
        self::assertSame(2.5, $rendered['backend/emission/report/overview.html.twig']['reportData']['actividad']['Agua']);
        self::assertSame($activityLabel, $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['activity']);
        self::assertSame('backend.emission.water_v1.units.m3', $rendered['backend/emission/report/detailed.html.twig']['recordPresentations'][0]['unit']);
        self::assertSame(2.5, $rendered['backend/emission/report/by_activity.html.twig']['data'][$activityLabel]['actividad']);
        self::assertSame('Agua', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories'][$activityLabel]);
    }
}
