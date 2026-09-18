<?php

namespace App\Controller\Backend;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
use App\Repository\BgosSubcategoryConfigRepository;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\Bgos\BgosEmissionEntryContextResolver;
use App\Service\Bgos\BgosPeriodService;
use App\Service\Bgos\BgosPeriodWindowResolver;
use App\Service\Bgos\BgosSubcategoryCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/bgos', name: 'backend_bgos_')]
#[IsGranted('ROLE_USER')]
final class BgosController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        ActiveProjectService $activeProjectService,
        BgosPeriodWindowResolver $windowResolver,
        BgosPeriodService $periodService,
        Request $request,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $today = new \DateTimeImmutable('today');
        $view = $request->query->getString('view', BgosPeriodWindowResolver::VIEW_DAY);
        $selectedDate = $this->selectedDate($request->query->getString('date'));

        $window = $windowResolver->resolve(
            $project,
            $view,
            $selectedDate,
            $today,
        );

        $period = null;
        if (null !== $window) {
            $period = $periodService->build(
                $project,
                $window->startDate,
                $window->endDate,
                $today,
                BgosPeriodWindowResolver::VIEW_TOTAL === $window->view,
            );
        }

        return $this->render('backend/bgos/agenda.html.twig', [
            'project' => $project,
            'window' => $window,
            'period' => $period,
            'views' => BgosPeriodWindowResolver::VIEWS,
            'entryRoutes' => BgosEmissionEntryContextResolver::ROUTES,
        ]);
    }

    #[Route('/config', name: 'config', methods: ['GET'])]
    public function config(
        ActiveProjectService $activeProjectService,
        BgosSubcategoryConfigRepository $repository,
        BgosSubcategoryCatalog $catalog,
        Request $request,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $configs = [];
        foreach ($repository->findBy(['project' => $project]) as $config) {
            $configs[$config->getCategoryKey()][$config->getSubcategoryKey()] = $config;
        }

        $categories = $catalog->categories();
        $requestedOpenCategory = $request->query->getString('open');
        $categoryKeys = array_column($categories, 'key');
        $openCategory = in_array($requestedOpenCategory, $categoryKeys, true)
            ? $requestedOpenCategory
            : ($categoryKeys[0] ?? null);

        foreach ($categories as &$category) {
            $category['open'] = $category['key'] === $openCategory;
            $previousGroupKey = null;

            foreach ($category['subcategories'] as &$definition) {
                $config = $configs[$definition['categoryKey']][$definition['subcategoryKey']] ?? null;
                $definition['config'] = $config;
                $definition['active'] = $config?->isActive() ?? true;
                $definition['preproductionFrequency'] = $config?->getPreproductionFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['activityFrequency'] = $config?->getActivityFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['postproductionFrequency'] = $config?->getPostproductionFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['showGroup'] = null !== $definition['groupKey']
                    && $definition['groupKey'] !== $previousGroupKey;
                $previousGroupKey = $definition['groupKey'];
            }
            unset($definition);
        }
        unset($category);

        return $this->render('backend/bgos/config.html.twig', [
            'project' => $project,
            'categories' => $categories,
            'frequencies' => BgosSubcategoryConfig::FREQUENCIES,
            'phaseLabels' => [
                'preproduction' => $project->getPhaseLabel('preproduccion'),
                'activity' => $project->getPhaseLabel('actividad'),
                'postproduction' => $project->getPhaseLabel('postproduccion'),
            ],
        ]);
    }

    #[Route(
        '/subcategory/{categoryKey}/{subcategoryKey}',
        name: 'subcategory_save',
        methods: ['POST'],
        requirements: [
            'categoryKey' => '[a-z]+',
            'subcategoryKey' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ],
    )]
    public function save(
        string $categoryKey,
        string $subcategoryKey,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosSubcategoryConfigRepository $repository,
        BgosSubcategoryCatalog $catalog,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $definition = $catalog->find($categoryKey, $subcategoryKey);
        if (null === $definition) {
            throw $this->createNotFoundException();
        }

        $tokenId = sprintf(
            'bgos_subcategory_save_%s_%s',
            $categoryKey,
            $subcategoryKey,
        );

        if (!$this->isCsrfTokenValid(
            $tokenId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_config', [
                'open' => $categoryKey,
            ]);
        }

        $frequencies = $this->readFrequencies($request);
        if (null === $frequencies) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_config', [
                'open' => $categoryKey,
            ]);
        }

        $config = $repository->findOneBy([
            'project' => $project,
            'categoryKey' => $categoryKey,
            'subcategoryKey' => $subcategoryKey,
        ]);

        $created = null === $config;

        $config ??= (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey($definition['categoryKey'])
            ->setSubcategoryKey($definition['subcategoryKey']);

        $config
            ->setLabel($definition['label'])
            ->setActive($request->request->getBoolean('active'))
            ->setPreproductionFrequency($frequencies['preproduction'])
            ->setActivityFrequency($frequencies['activity'])
            ->setPostproductionFrequency($frequencies['postproduction']);

        if ($created) {
            $entityManager->persist($config);
        }

        $entityManager->flush();

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_config', [
            'open' => $categoryKey,
            '_fragment' => sprintf(
                'bgos-%s-%s',
                $categoryKey,
                $subcategoryKey,
            ),
        ]);
    }

    private function activeProject(
        ActiveProjectService $activeProjectService,
    ): Project {
        $project = $activeProjectService->getActiveProject();

        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }

        return $project;
    }

    private function selectedDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (
            false === $date
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }

    /** @return array{preproduction: string, activity: string, postproduction: string}|null */
    private function readFrequencies(Request $request): ?array
    {
        $frequencies = [
            'preproduction' => $request->request->getString('preproductionFrequency'),
            'activity' => $request->request->getString('activityFrequency'),
            'postproduction' => $request->request->getString('postproductionFrequency'),
        ];

        foreach ($frequencies as $frequency) {
            if (!in_array(
                $frequency,
                BgosSubcategoryConfig::FREQUENCIES,
                true,
            )) {
                return null;
            }
        }

        return $frequencies;
    }
}
