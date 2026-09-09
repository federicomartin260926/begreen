<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\MaterialEmissionController;
use App\DataFixtures\MaterialEmissionFactorFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionFactorRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Material\MaterialAmountNormalizer;
use App\Service\Emission\Material\MaterialEmissionCalculator;
use App\Service\Emission\Material\MaterialEmissionRecordService;
use App\Service\Emission\Material\MaterialEmissionRequestMapper;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Material\MaterialFactorResolver;
use App\Service\Emission\Material\MaterialUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MaterialEmissionControllerTest extends KernelTestCase
{
    public function testRendersModernMaterialFormWithBackendCatalog(): void
    {
        $context = $this->context();
        $ignored = null;
        $response = $this->controller()->create(
            $this->request('GET'),
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(0, $ignored),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringContainsString('data-controller="material-v1-form"', $content);
        self::assertStringContainsString('Materiales y Productos', $content);
        self::assertStringNotContainsString('densidad_kg_m3', $content);
        self::assertSame(1, preg_match('/data-material-v1-form-catalog-value="([^"]+)"/', $content, $matches));
        $catalog = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(14, $catalog['families']);
        self::assertContains('Pilas y baterías', array_column($catalog['families'], 'label'));
    }

    public function testPreviewDistinguishesConvertedFactorRuleZeroAndUnavailableFactor(): void
    {
        $context = $this->context();
        $controller = $this->controller();

        $paint = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_PAINT,
            'subproduct' => 'Pintura con base al agua',
            'measurementMethod' => 'volume',
            'inputQuantity' => '10',
            'inputUnit' => 'l',
        ]);
        self::assertSame('CALCULATED', $paint['status']);
        self::assertSame('16', $paint['normalizedAmount']);
        self::assertSame('34.45879792', $paint['emissionKgCo2e']);
        self::assertSame('VERSIONED', $paint['factorTraces'][0]['temporalType']);
        self::assertNull($paint['factorTraces'][0]['factorYear']);

        $rule = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => MaterialUiCatalog::ACTIVITY_PAPER,
            'origin' => 'Reutilizado',
            'measurementMethod' => 'weight',
            'inputQuantity' => '12',
            'inputUnit' => 'kg',
        ]);
        self::assertSame('CALCULATED', $rule['status']);
        self::assertSame('0', $rule['emissionKgCo2e']);
        self::assertSame('RULE', $rule['factorTraces'][0]['temporalType']);

        $unavailable = $this->preview($controller, $context['active'], [
            ...$this->basePost(),
            'activity' => 'Plástico promedio',
            'origin' => 'Reutilizado',
            'measurementMethod' => 'weight',
            'inputQuantity' => '10',
            'inputUnit' => 'kg',
        ]);
        self::assertSame('NOT_AUTOMATICALLY_CALCULABLE', $unavailable['status']);
        self::assertNull($unavailable['emissionKgCo2e']);
        self::assertNotSame('0', $unavailable['emissionKgCo2e']);
    }

    public function testCreatePersistsModernRecordAndCompleteSnapshot(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->woodPost() + ['amount' => '999', 'emission' => '999']);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_create'));
        $persisted = null;

        $response = $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(1, $persisted),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame('Materiales', $persisted->getCategory()?->getName());
        self::assertSame(100.0, $persisted->getAmount());
        self::assertSame(26.950416, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());

        $snapshot = json_decode((string) $persisted->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('material-v1', $snapshot['version']);
        self::assertSame('wood', $snapshot['input']['family']);
        self::assertSame('Madera', $snapshot['input']['activity']);
        self::assertSame('100', $snapshot['calculation']['normalizedAmount']);
        self::assertSame(2026, $snapshot['calculation']['factorTraces'][0]['factorYear']);
        self::assertArrayHasKey('metadata', $snapshot['calculation']['factorTraces'][0]);
        self::assertStringNotContainsString('999', (string) $persisted->getCalculationDetails());
    }

    public function testEditRecalculatesAndDuplicateOnlyPrefillsFunctionalData(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $post = $this->woodPost();
        $post['inputQuantity'] = '20';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('material_emission_v1_edit_300'));
        $ignored = null;

        $response = $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new MaterialEmissionRequestMapper(new MaterialUiCatalog()),
            $this->recordService(1, $ignored),
            new MaterialEmissionSnapshot(),
            new MaterialUiCatalog(),
            $this->storage(),
            $this->attachmentManager(),
        );
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(20.0, $record->getAmount());
        self::assertSame(5.3900832, $record->getEmission());

        $duplicate = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new MaterialEmissionSnapshot(),
            new MaterialUiCatalog(),
        );
        $content = (string) $duplicate->getContent();
        self::assertStringContainsString('action="/backend/emission/new-material-v1?', $content);
        self::assertStringContainsString('value="20"', $content);
        self::assertStringNotContainsString('material_emission_v1_edit_300', $content);
    }

    /** @return array<string, mixed> */
    private function preview(MaterialEmissionController $controller, ActiveProjectService $active, array $post): array
    {
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('material_emission_v1_preview'));
        $response = $controller->preview($request, $active, new MaterialEmissionRequestMapper(new MaterialUiCatalog()), $this->calculator());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Materiales')->setType('rodaje')->setCountry('ES');
        $this->setId($project, 10);
        $category = (new Category())->setName('Materiales');
        $this->setId($category, 20);
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2022-01-01'))
            ->setEndDate(new \DateTimeImmutable('2027-12-31'));
        $project->addPhaseDate($phase);
        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phase);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    private function controller(): MaterialEmissionController
    {
        $controller = new MaterialEmissionController();
        $controller->setContainer(self::getContainer());
        $user = (new \App\Entity\User())
            ->setName('Admin')->setSurnames('User')->setEmail('admin@example.test')
            ->setPassword('password')->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = []): Request
    {
        $request = new Request($query, $post, [], [], [], ['REQUEST_METHOD' => $method]);
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_emission_new_material_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): MaterialEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new MaterialEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    'material' === $categoryKey
                    && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && (int) $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => (int) $right->getYear() <=> (int) $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('material' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );
        $catalog = new MaterialUiCatalog();

        return new MaterialEmissionCalculator(
            $catalog,
            new MaterialAmountNormalizer($catalog),
            new MaterialFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog),
        );
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): MaterialEmissionRecordService
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                if ($entity instanceof EmissionRecord) {
                    $persisted = $entity;
                    if (null === $entity->getId()) {
                        $this->setId($entity, 301);
                    }
                }
            },
        );
        $manager->expects(self::exactly($persistCalls))->method('flush');

        return new MaterialEmissionRecordService($this->calculator(), new MaterialEmissionSnapshot(), $manager);
    }

    private function attachmentManager(): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        return $manager;
    }

    private function storage(): EmissionRecordAttachmentStorage
    {
        return new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-material-test');
    }

    /** @param array<string, mixed> $context */
    private function record(array $context): EmissionRecord
    {
        $input = (new MaterialEmissionRequestMapper(new MaterialUiCatalog()))->map($this->request('POST', $this->woodPost()));
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])->setPhase($context['phase'])->setCategory($context['category'])
            ->setAmount((float) $result->normalizedAmount)->setEmission((float) $result->emissionKgCo2e)
            ->setStatus($result->status)->setRegisteredAt(new \DateTimeImmutable('2026-01-01'))
            ->setCalculationDetails((new MaterialEmissionSnapshot())->encode($input, $result));
        $this->setId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function basePost(): array
    {
        return ['startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'country' => 'ESP'];
    }

    /** @return array<string, string> */
    private function woodPost(): array
    {
        return [
            ...$this->basePost(),
            'activity' => 'Madera',
            'family' => 'wood',
            'origin' => 'Producción de materia prima',
            'measurementMethod' => 'weight',
            'inputQuantity' => '100',
            'inputUnit' => 'kg',
            'notes' => 'Madera comprada',
        ];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }
}
