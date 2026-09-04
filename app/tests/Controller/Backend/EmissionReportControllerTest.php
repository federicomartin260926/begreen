<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionReportController;
use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\PdfService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmissionReportControllerTest extends TestCase
{
    public function testOverviewAndActivityReportSupportRecordWithoutActivity(): void
    {
        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-01-31'));
        $category = (new Category())->setName('Transporte');
        $record = (new EmissionRecord())
            ->setProject($project)
            ->setPhase($phase)
            ->setCategory($category)
            ->setAmount(10)
            ->setEmission(2.5)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-15'));

        $activeProject = $this->createMock(ActiveProjectService::class);
        $activeProject->method('getActiveProject')->willReturn($project);
        $repository = $this->createMock(EmissionRecordRepository::class);
        $repository->method('findBy')->willReturn([$record]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $rendered = [];
        $pdf = $this->createMock(PdfService::class);
        $pdf->expects(self::exactly(2))->method('renderPdf')->willReturnCallback(
            static function (string $template, array $data) use (&$rendered): Response {
                $rendered[$template] = $data;

                return new Response('pdf');
            },
        );

        $controller = new EmissionReportController();
        $controller->overview($activeProject, $repository, $pdf, $translator);
        $controller->emissionsByActivityPdf($activeProject, $repository, $pdf, $translator);

        self::assertSame(2.5, $rendered['backend/emission/report/overview.html.twig']['reportData']['actividad']['Transporte']);
        self::assertSame(2.5, $rendered['backend/emission/report/by_activity.html.twig']['data']['backend.common.no_activity']['actividad']);
        self::assertSame('Transporte', $rendered['backend/emission/report/by_activity.html.twig']['activityCategories']['backend.common.no_activity']);
    }
}
