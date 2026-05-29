<?php

namespace App\Controller\Admin;

use App\Repository\MeasureBlockRepository;
use App\Repository\ProtocolRepository;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/measure-blocks')]
final class MeasureBlockAjaxController extends AbstractController
{
    #[Route('/by-protocol', name: 'admin_measure_blocks_by_protocol', methods: ['GET'])]
    public function byProtocol(
        Request $request,
        ProtocolRepository $protocolRepository,
        MeasureBlockRepository $measureBlockRepository,
        TranslatableListener $translatableListener
    ): JsonResponse {
        $translatableListener->setTranslatableLocale($request->getLocale());

        $id = (int) $request->query->get('id');
        if ($id <= 0) {
            return $this->json([]);
        }

        $protocol = $protocolRepository->find($id);
        if (!$protocol) {
            return $this->json([]);
        }

        $blocks = $measureBlockRepository->findActiveByProtocol($protocol);

        return $this->json(array_map(static fn ($block): array => [
            'id' => $block->getId(),
            'name' => $block->getName(),
        ], $blocks));
    }
}
