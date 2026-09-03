<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\CrewAjaxController;
use App\Entity\CrewDepartment;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewAjaxControllerTest extends KernelTestCase
{
    public function testReturnsOnlyDepartmentPositionsInDocumentOrder(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var CrewDepartmentRepository $departmentRepository */
        $departmentRepository = $container->get(CrewDepartmentRepository::class);
        /** @var CrewPositionRepository $positionRepository */
        $positionRepository = $container->get(CrewPositionRepository::class);
        $department = $departmentRepository->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'ARTE',
        ]);
        self::assertInstanceOf(CrewDepartment::class, $department);

        $controller = new CrewAjaxController();
        $controller->setContainer($container);
        $response = $controller->positionsByDepartment($department, $positionRepository);
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(10, $data);
        self::assertSame('Diseñador/a de producción', $data[0]['name']);
        self::assertSame('Director/a de arte', $data[1]['name']);
        self::assertSame('Concept art', $data[9]['name']);
    }
}
