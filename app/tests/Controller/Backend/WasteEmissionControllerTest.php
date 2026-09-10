<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\WasteEmissionController;
use App\DataFixtures\WasteEmissionFactorFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\EmissionRecordAttachment;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionFactorRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionRecordService;
use App\Service\Emission\Waste\WasteEmissionRequestMapper;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteFactorResolver;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class WasteEmissionControllerTest extends KernelTestCase
{
    public function testPreviewCalculatesOfficialWasteFactor(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->post());
        $request->request->set(
            '_preview_token',
            $this->csrfToken('waste_emission_v1_preview'),
        );

        $response = $this->controller()->preview(
            $request,
            $context['active'],
            new WasteEmissionRequestMapper(),
            $this->calculator(),
        );
        $data = json_decode(
            (string) $response->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('2.4542', $data['emissionKgCo2e']);
        self::assertSame('OCCC', $data['factorTraces'][0]['source']);
        self::assertSame('VERSIONED', $data['factorTraces'][0]['temporalType']);
    }

    public function testCreatePersistsModernBackendCalculation(): void
    {
        $context = $this->context();
        $request = $this->request(
            'POST',
            $this->post() + ['amount' => '999', 'emission' => '999'],
        );
        $request->request->set(
            '_token',
            $this->csrfToken('waste_emission_v1_create'),
        );
        $persisted = null;

        $response = $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new WasteEmissionRequestMapper(),
            $this->recordService(1, $persisted),
            new WasteUiCatalog(),
            new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-waste-test'),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame(10.0, $persisted->getAmount());
        self::assertSame(2.4542, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());
        self::assertStringContainsString(
            '"version":"waste-v1"',
            (string) $persisted->getCalculationDetails(),
        );
        self::assertStringNotContainsString(
            '999',
            (string) $persisted->getCalculationDetails(),
        );
    }

    public function testCrossYearCreateIsRejectedWithSplitInstruction(): void
    {
        $context = $this->context();
        $post = $this->post();
        $post['startDate'] = '2025-12-31';
        $post['endDate'] = '2026-01-01';
        $request = $this->request('POST', $post);
        $request->request->set(
            '_token',
            $this->csrfToken('waste_emission_v1_create'),
        );
        $persisted = null;

        $response = $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new WasteEmissionRequestMapper(),
            $this->recordService(0, $persisted),
            new WasteUiCatalog(),
            new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-waste-test'),
            $this->attachmentManager(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNull($persisted);
        self::assertStringContainsString(
            'dividir',
            mb_strtolower((string) $response->getContent()),
        );
    }

    public function testEditRecalculatesAndDuplicateDoesNotCopyAttachments(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Duplicar residuos');
        $record->addAttachment(
            (new EmissionRecordAttachment())
                ->setEmissionRecord($record)
                ->setOriginalName('no-copiar.pdf')
                ->setStoredName(str_repeat('a', 32).'.pdf')
                ->setMimeType('application/pdf')
                ->setSize(10)
                ->setCreatedAt(new \DateTimeImmutable()),
        );

        $post = $this->post();
        $post['weight'] = '20';
        $post['emission'] = '777';
        $request = $this->request('POST', $post);
        $request->request->set(
            '_token',
            $this->csrfToken('waste_emission_v1_edit_300'),
        );
        $ignored = null;

        $response = $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new WasteEmissionRequestMapper(),
            $this->recordService(1, $ignored),
            new WasteEmissionSnapshot(),
            new WasteUiCatalog(),
            new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-waste-test'),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(20.0, $record->getAmount());
        self::assertSame(4.9084, $record->getEmission());
        self::assertStringNotContainsString(
            '777',
            (string) $record->getCalculationDetails(),
        );

        $duplicate = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new WasteEmissionSnapshot(),
            new WasteUiCatalog(),
        );
        $content = (string) $duplicate->getContent();

        self::assertStringContainsString(
            'action="/backend/emission/new-waste-v1?',
            $content,
        );
        self::assertStringContainsString('Duplicar residuos', $content);
        self::assertSame(1, preg_match(
            '/data-waste-v1-form-initial-value="([^"]+)"/',
            $content,
            $matches,
        ));
        $initial = json_decode(
            html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('Orgánico (residuos de jardín)', $initial['wasteType']);
        self::assertNull($initial['wasteActivity']);
        self::assertSame('Compostaje', $initial['treatment']);
        self::assertStringNotContainsString('no-copiar.pdf', $content);
    }

    /** @return array{project:Project,category:Category,phase:ProjectPhaseDate,active:ActiveProjectService&MockObject,categories:CategoryRepository&MockObject,projects:ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())
            ->setName('Proyecto Residuos')
            ->setType('rodaje')
            ->setCountry('ES');
        $this->setId($project, 10);

        $category = (new Category())->setName('Residuos');
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

        return compact(
            'project',
            'category',
            'phase',
            'active',
            'categories',
            'projects',
        );
    }

    private function controller(): WasteEmissionController
    {
        $controller = new WasteEmissionController();
        $controller->setContainer(self::getContainer());
        $user = (new \App\Entity\User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $controller;
    }

    private function request(
        string $method,
        array $post = [],
        array $query = [],
    ): Request {
        $request = new Request(
            $query,
            $post,
            [],
            [],
            [],
            ['REQUEST_METHOD' => $method],
        );
        $request->setLocale('es');
        $request->attributes->set(
            '_route',
            'backend_emission_new_waste_v1',
        );
        $request->attributes->set('_route_params', []);
        $request->setSession(
            new Session(new MockArraySessionStorage()),
        );
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($id)
            ->getValue();
    }

    private function calculator(): WasteEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(
            static function (object $factor) use (&$factors): void {
                if ($factor instanceof EmissionFactor) {
                    $factors[] = $factor;
                }
            },
        );
        (new WasteEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForApplicabilityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool =>
                        'waste' === $categoryKey
                        && $factor->getFunctionalKey() === $functionalKey
                        && null !== $factor->getActivityYear()
                        && $factor->getActivityYear() <= $activityYear
                        && (null === $factor->getYear() || $factor->getYear() <= $activityYear),
                );
                usort(
                    $candidates,
                    static fn (EmissionFactor $left, EmissionFactor $right): int =>
                        $right->getActivityYear() <=> $left->getActivityYear()
                        ?: strcmp((string) $left->getFactorId(), (string) $right->getFactorId()),
                );

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (
                string $categoryKey,
                string $functionalKey,
                string $temporalType,
            ) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if (
                        'waste' === $categoryKey
                        && $temporalType === $factor->getTemporalType()
                        && $functionalKey === $factor->getFunctionalKey()
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new WasteUiCatalog();

        return new WasteEmissionCalculator(
            $catalog,
            new WasteFactorResolver(
                new EmissionFactorResolver($repository, $keyGenerator),
                $catalog,
            ),
        );
    }

    private function recordService(
        int $persistCalls,
        ?EmissionRecord &$persisted,
    ): WasteEmissionRecordService {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly($persistCalls))
            ->method('persist')
            ->willReturnCallback(
                function (object $entity) use (&$persisted): void {
                    if ($entity instanceof EmissionRecord) {
                        $persisted = $entity;
                        if (null === $entity->getId()) {
                            $this->setId($entity, 301);
                        }
                    }
                },
            );
        $entityManager
            ->expects(self::exactly($persistCalls))
            ->method('flush');

        return new WasteEmissionRecordService(
            $this->calculator(),
            new WasteEmissionSnapshot(),
            $entityManager,
        );
    }

    private function attachmentManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    /** @param array<string, mixed> $context */
    private function record(
        array $context,
        ?string $notes = null,
    ): EmissionRecord {
        $input = (new WasteEmissionRequestMapper())->map(
            $this->request('POST', $this->post()),
        );
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])
            ->setPhase($context['phase'])
            ->setCategory($context['category'])

            ->setAmount((float) $result->normalizedAmount)
            ->setEmission((float) $result->emissionKgCo2e)
            ->setStatus($result->status)
            ->setRegisteredAt(new \DateTimeImmutable('2025-01-01'))
            ->setNotes($notes)
            ->setCalculationDetails(
                (new WasteEmissionSnapshot())->encode($input, $result),
            );
        $this->setId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function post(): array
    {
        return [
            'startDate' => '2025-01-01',
            'endDate' => '2025-01-02',
            'country' => 'ESP',
            'wasteType' => 'Orgánico (residuos de jardín)',
            'treatment' => 'Compostaje',
            'weight' => '10',
            'weightUnit' => 'kg',
            'notes' => 'Residuo jardín',
        ];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))
            ->getProperty('id')
            ->setValue($entity, $id);
    }
}
