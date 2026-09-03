<?php

namespace App\Controller\Backend;

use App\Entity\CrewDepartment;
use App\Repository\CrewPositionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/backend/ajax', name: 'backend_ajax_')]
class CrewAjaxController extends AbstractController
{
    #[Route('/crew-positions-by-department/{id}', name: 'crew_positions_by_department', methods: ['GET'])]
    public function positionsByDepartment(CrewDepartment $department, CrewPositionRepository $repository): JsonResponse
    {
        $positions = $repository->findByCrewDepartment($department);

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
