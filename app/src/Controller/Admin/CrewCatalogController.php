<?php

namespace App\Controller\Admin;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Form\CrewDepartmentType;
use App\Form\CrewPositionType;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/crew-catalog', name: 'admin_crew_catalog_')]
final class CrewCatalogController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, CrewDepartmentRepository $repository): Response
    {
        $scope = (string) $request->query->get('scope', CrewDepartment::SCOPE_FILMING);
        $this->assertValidScope($scope);

        return $this->render('admin/crew_catalog/index.html.twig', [
            'scope' => $scope,
            'scopes' => CrewDepartment::SCOPES,
            'departments' => $repository->findByScope($scope),
        ]);
    }

    #[Route('/{scope}/departments/new', name: 'department_new', methods: ['GET', 'POST'], requirements: ['scope' => 'rodaje|evento|animacion'])]
    public function newDepartment(
        string $scope,
        Request $request,
        EntityManagerInterface $entityManager,
        CrewDepartmentRepository $repository,
        TranslatableListener $translatableListener,
    ): Response {
        $this->assertValidScope($scope);
        $department = (new CrewDepartment())->setScope($scope);
        $translatableListener->setTranslatableLocale('es');
        $translatableListener->setTranslationFallback(false);
        $form = $this->createForm(CrewDepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($department->getSortOrder() <= 0) {
                $department->setSortOrder($repository->nextSortOrderForScope((string) $department->getScope()));
            }

            $entityManager->persist($department);
            $entityManager->flush();
            $this->saveTranslations($entityManager, $department, $form, ['name']);
            $entityManager->flush();
            $this->restoreLocale($request, $translatableListener);
            $this->addFlash('success', 'backend.crew_catalog.flash.department_created');

            return $this->redirectToRoute('admin_crew_catalog_index', ['scope' => $department->getScope()]);
        }

        $this->restoreLocale($request, $translatableListener);

        return $this->render('admin/crew_catalog/department_form.html.twig', [
            'form' => $form->createView(),
            'department' => $department,
            'edit' => false,
            'return_scope' => $scope,
        ]);
    }

    #[Route('/departments/{id}/edit', name: 'department_edit', methods: ['GET', 'POST'])]
    public function editDepartment(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatableListener $translatableListener,
    ): Response {
        $translatableListener->setTranslatableLocale('es');
        $translatableListener->setTranslationFallback(false);
        $department = $entityManager->getRepository(CrewDepartment::class)->find($id);
        if (!$department instanceof CrewDepartment) {
            throw $this->createNotFoundException();
        }
        $entityManager->refresh($department);

        $form = $this->createForm(CrewDepartmentType::class, $department, [
            'translations' => $this->findTranslations($entityManager, $department),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveTranslations($entityManager, $department, $form, ['name']);
            $entityManager->flush();
            $this->restoreLocale($request, $translatableListener);
            $this->addFlash('success', 'backend.crew_catalog.flash.department_updated');

            return $this->redirectToRoute('admin_crew_catalog_index', ['scope' => $department->getScope()]);
        }

        $this->restoreLocale($request, $translatableListener);

        return $this->render('admin/crew_catalog/department_form.html.twig', [
            'form' => $form->createView(),
            'department' => $department,
            'edit' => true,
            'return_scope' => $department->getScope(),
        ]);
    }

    #[Route('/departments/{id}/delete', name: 'department_delete', methods: ['POST'])]
    public function deleteDepartment(CrewDepartment $department, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $scope = (string) $department->getScope();
        if (!$this->isCsrfTokenValid('delete_crew_department_'.$department->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.common.csrf_invalid');

            return $this->redirectToRoute('admin_crew_catalog_index', ['scope' => $scope]);
        }

        $entityManager->remove($department);
        $entityManager->flush();
        $this->addFlash('success', 'backend.crew_catalog.flash.department_deleted');

        return $this->redirectToRoute('admin_crew_catalog_index', ['scope' => $scope]);
    }

    #[Route('/departments/{id}/positions', name: 'positions', methods: ['GET'])]
    public function positions(CrewDepartment $department, CrewPositionRepository $repository): Response
    {
        return $this->render('admin/crew_catalog/positions.html.twig', [
            'department' => $department,
            'positions' => $repository->findByCrewDepartment($department),
        ]);
    }

    #[Route('/departments/{id}/positions/new', name: 'position_new', methods: ['GET', 'POST'])]
    public function newPosition(
        CrewDepartment $department,
        Request $request,
        EntityManagerInterface $entityManager,
        CrewPositionRepository $repository,
        TranslatableListener $translatableListener,
    ): Response {
        $position = (new CrewPosition())->setCrewDepartment($department);
        $translatableListener->setTranslatableLocale('es');
        $translatableListener->setTranslationFallback(false);
        $form = $this->createForm(CrewPositionType::class, $position);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedDepartment = $position->getCrewDepartment();
            if (!$selectedDepartment instanceof CrewDepartment) {
                throw new \LogicException('A crew position requires a department.');
            }
            if ($position->getSortOrder() <= 0) {
                $position->setSortOrder($repository->nextSortOrderForDepartment($selectedDepartment));
            }

            $entityManager->persist($position);
            $entityManager->flush();
            $this->saveTranslations($entityManager, $position, $form, ['name', 'description']);
            $entityManager->flush();
            $this->restoreLocale($request, $translatableListener);
            $this->addFlash('success', 'backend.crew_catalog.flash.position_created');

            return $this->redirectToRoute('admin_crew_catalog_positions', ['id' => $selectedDepartment->getId()]);
        }

        $this->restoreLocale($request, $translatableListener);

        return $this->render('admin/crew_catalog/position_form.html.twig', [
            'form' => $form->createView(),
            'position' => $position,
            'edit' => false,
            'return_department' => $department,
        ]);
    }

    #[Route('/positions/{id}/edit', name: 'position_edit', methods: ['GET', 'POST'])]
    public function editPosition(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatableListener $translatableListener,
    ): Response {
        $translatableListener->setTranslatableLocale('es');
        $translatableListener->setTranslationFallback(false);
        $position = $entityManager->getRepository(CrewPosition::class)->find($id);
        if (!$position instanceof CrewPosition) {
            throw $this->createNotFoundException();
        }
        $entityManager->refresh($position);
        $returnDepartment = $position->getCrewDepartment();

        $form = $this->createForm(CrewPositionType::class, $position, [
            'translations' => $this->findTranslations($entityManager, $position),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveTranslations($entityManager, $position, $form, ['name', 'description']);
            $entityManager->flush();
            $this->restoreLocale($request, $translatableListener);
            $this->addFlash('success', 'backend.crew_catalog.flash.position_updated');

            return $this->redirectToRoute('admin_crew_catalog_positions', [
                'id' => $position->getCrewDepartment()?->getId(),
            ]);
        }

        $this->restoreLocale($request, $translatableListener);

        return $this->render('admin/crew_catalog/position_form.html.twig', [
            'form' => $form->createView(),
            'position' => $position,
            'edit' => true,
            'return_department' => $returnDepartment,
        ]);
    }

    #[Route('/positions/{id}/delete', name: 'position_delete', methods: ['POST'])]
    public function deletePosition(CrewPosition $position, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $departmentId = $position->getCrewDepartment()?->getId();
        if (!$this->isCsrfTokenValid('delete_crew_position_'.$position->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.common.csrf_invalid');

            return $this->redirectToRoute('admin_crew_catalog_positions', ['id' => $departmentId]);
        }

        $entityManager->remove($position);
        $entityManager->flush();
        $this->addFlash('success', 'backend.crew_catalog.flash.position_deleted');

        return $this->redirectToRoute('admin_crew_catalog_positions', ['id' => $departmentId]);
    }

    private function assertValidScope(string $scope): void
    {
        if (!in_array($scope, CrewDepartment::SCOPES, true)) {
            throw $this->createNotFoundException();
        }
    }

    /** @return array<string, array<string, string>> */
    private function findTranslations(EntityManagerInterface $entityManager, object $entity): array
    {
        /** @var TranslationRepository $repository */
        $repository = $entityManager->getRepository(Translation::class);

        return $repository->findTranslations($entity);
    }

    /** @param list<string> $fields */
    private function saveTranslations(
        EntityManagerInterface $entityManager,
        object $entity,
        FormInterface $form,
        array $fields,
    ): void {
        /** @var TranslationRepository $repository */
        $repository = $entityManager->getRepository(Translation::class);

        foreach ($fields as $field) {
            $formField = $field.'_en';
            $value = trim((string) $form->get($formField)->getData());
            $repository->translate($entity, $field, 'en', $value !== '' ? $value : null);
        }
    }

    private function restoreLocale(Request $request, TranslatableListener $translatableListener): void
    {
        $translatableListener->setTranslatableLocale($request->getLocale());
        $translatableListener->setTranslationFallback(true);
    }
}
