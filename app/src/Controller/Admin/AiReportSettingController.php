<?php

namespace App\Controller\Admin;

use App\Form\AiReportSettingType;
use App\Service\Ai\AiProviderAvailability;
use App\Service\Ai\AiReportSettingResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\FormError;

#[Route('/admin/ai', name: 'admin_ai_')]
final class AiReportSettingController extends AbstractController
{
    #[Route('/', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        AiReportSettingResolver $resolver,
        AiProviderAvailability $providerAvailability,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $setting = $resolver->editableSetting();
        $form = $this->createForm(AiReportSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $submittedData = $request->request->all($form->getName());
            $submittedProvider = is_string($submittedData['provider'] ?? null)
                ? $submittedData['provider']
                : $setting->getProvider();

            if (in_array($submittedProvider, ['openai', 'anthropic'], true)
                && !$providerAvailability->isAvailable($submittedProvider)
            ) {
                $form->get('provider')->addError(new FormError('backend.ai.validation.provider_unavailable'));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $setting->touch();
            $entityManager->persist($setting);
            $entityManager->flush();

            $this->addFlash('success', 'backend.ai.flash.saved');

            return $this->redirectToRoute('admin_ai_edit');
        }

        return $this->render('admin/ai/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
