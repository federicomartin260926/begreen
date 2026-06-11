<?php

namespace App\Controller\Admin;

use App\Repository\DepartmentRepository;
use App\Repository\ProtocolRepository;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/departments')]
class DepartmentAjaxController extends AbstractController
{
    #[Route('/by-protocol', name: 'admin_departments_by_protocol', methods: ['GET'])]
    public function byProtocol(
        Request $request,
        ProtocolRepository $protocolRepo,
        DepartmentRepository $departmentRepo,
        TranslatableListener $translatableListener
    ): JsonResponse {
        // Mostrar los nombres en el idioma visible de la UI (EN/ES)
        $translatableListener->setTranslatableLocale($request->getLocale());

        $id = (int) $request->query->get('id');
        if ($id <= 0) {
            // Si no hay protocolo puedes devolver todos los deptos o ninguno. Aquí: ninguno.
            return $this->json([]);
        }

        $protocol = $protocolRepo->find($id);
        if (!$protocol) {
            return $this->json([]);
        }

        $type = $protocol->getType(); // 'rodaje' | 'evento' | 'ambos'

        $qb = $departmentRepo->createQueryBuilder('d')
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC');

        if ($type === 'rodaje' || $type === 'evento') {
            $qb->andWhere('(d.projectType = :t OR d.projectType IS NULL)')
               ->setParameter('t', $type);
        }

        $departments = $qb->getQuery()->getResult();

        $out = array_map(static fn($d) => [
            'id'   => $d->getId(),
            'name' => $d->getName(), // saldrá traducido según el locale fijado arriba
        ], $departments);

        return $this->json($out);
    }
}
