<?php

namespace App\Controller\Backend;

use App\Entity\Department;
use App\Repository\PositionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/backend/ajax', name: 'backend_ajax_')]
class CrewAjaxController extends AbstractController
{
    #[Route('/positions-by-department/{id}', name: 'positions_by_department', methods: ['GET'])]
    public function positionsByDepartment(Department $department, PositionRepository $repo): JsonResponse
    {
        $positions = $repo->findByDepartment($department);

        $data = [];
        foreach ($positions as $p) {
            $data[] = [
                'id'   => $p->getId(),
                'name' => $p->getName(),
            ];
        }

        return $this->json($data);
    }
}
