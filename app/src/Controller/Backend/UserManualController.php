<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Service\UserManualProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/user-manual', name: 'backend_user_manual_')]
#[IsGranted('ROLE_USER')]
final class UserManualController extends AbstractController
{
    #[Route('/download', name: 'download', methods: ['GET'])]
    public function download(UserManualProvider $manualProvider, LoggerInterface $logger): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $manual = $manualProvider->resolveForCurrentUser();
        if (null === $manual) {
            $logger->warning('User manual download requested but no manual is available.', [
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);

            throw $this->createNotFoundException('User manual not available.');
        }

        $response = new BinaryFileResponse($manual->path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $manual->downloadName,
        );

        return $response;
    }
}
