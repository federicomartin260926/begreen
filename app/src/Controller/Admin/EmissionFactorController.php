<?php

namespace App\Controller\Admin;

use App\Entity\EmissionFactor;
use App\Form\EmissionFactorType;
use App\Repository\EmissionFactorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/emission-factors', name: 'admin_emission_factor_')]
final class EmissionFactorController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, EmissionFactorRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $filters = $this->filters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $paginator = $repository->findAdminPage($filters, $page, self::PER_PAGE);
        $total = count($paginator);

        return $this->render('admin/emission_factor/index.html.twig', [
            'factors' => iterator_to_array($paginator),
            'filters' => $filters,
            'categoryKeys' => $repository->findDistinctCategoryKeys(),
            'temporalTypes' => $this->temporalTypes(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EmissionFactorRepository $repository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $factor = (new EmissionFactor())
            ->setCategoryKey('')
            ->setFunctionalKey('')
            ->setCriteria([])
            ->setUnit('')
            ->setSource('');
        $form = $this->createForm(EmissionFactorType::class, $factor, [
            'category_keys' => $repository->findDistinctCategoryKeys(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $repository->findIdentityCollision(
                $factor->getCategoryKey(),
                $factor->getFunctionalKey(),
                $factor->getYear(),
            )) {
                $form->addError(new FormError($translator->trans('backend.admin.emission_factor.validation.duplicate_identity')));
            } else {
                $entityManager->persist($factor);
                $entityManager->flush();
                $this->addFlash('success', 'backend.admin.emission_factor.flash.created');

                return $this->redirectToRoute('admin_emission_factor_show', ['id' => $factor->getId()]);
            }
        }

        return $this->render('admin/emission_factor/form.html.twig', [
            'factor' => $factor,
            'form' => $form->createView(),
            'edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(EmissionFactor $factor): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/emission_factor/show.html.twig', ['factor' => $factor]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(Request $request, EmissionFactor $factor, EmissionFactorRepository $repository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $categoryKeys = $repository->findDistinctCategoryKeys();
        if (!in_array($factor->getCategoryKey(), $categoryKeys, true)) {
            $categoryKeys[] = $factor->getCategoryKey();
            sort($categoryKeys);
        }
        $form = $this->createForm(EmissionFactorType::class, $factor, ['category_keys' => $categoryKeys]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $repository->findIdentityCollision(
                $factor->getCategoryKey(),
                $factor->getFunctionalKey(),
                $factor->getYear(),
                $factor->getId(),
            )) {
                $form->addError(new FormError($translator->trans('backend.admin.emission_factor.validation.duplicate_identity')));
            } else {
                $entityManager->flush();
                $this->addFlash('success', 'backend.admin.emission_factor.flash.updated');

                return $this->redirectToRoute('admin_emission_factor_show', ['id' => $factor->getId()]);
            }
        }

        return $this->render('admin/emission_factor/form.html.twig', [
            'factor' => $factor,
            'form' => $form->createView(),
            'edit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(Request $request, EmissionFactor $factor, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_emission_factor_'.$factor->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.common.csrf_invalid');

            return $this->redirectToRoute('admin_emission_factor_show', ['id' => $factor->getId()]);
        }

        $entityManager->remove($factor);
        $entityManager->flush();
        $this->addFlash('success', 'backend.admin.emission_factor.flash.deleted');

        return $this->redirectToRoute('admin_emission_factor_index');
    }

    /** @return array{categoryKey?: string, temporalType?: string, year?: int, source?: string} */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['categoryKey', 'temporalType', 'source'] as $name) {
            $value = trim((string) $request->query->get($name, ''));
            if ('' !== $value) {
                $filters[$name] = $value;
            }
        }
        $year = trim((string) $request->query->get('year', ''));
        if (preg_match('/^\d+$/', $year)) {
            $filters['year'] = (int) $year;
        }

        return $filters;
    }

    /** @return list<string> */
    private function temporalTypes(): array
    {
        return [
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            EmissionFactor::TEMPORAL_TYPE_RULE,
            EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
            EmissionFactor::TEMPORAL_TYPE_PROXY_LCA,
        ];
    }
}
