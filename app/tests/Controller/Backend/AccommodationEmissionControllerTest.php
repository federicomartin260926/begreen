<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\AccommodationEmissionController;
use App\DataFixtures\AccommodationEmissionFactorFixtures;
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
use App\Service\Emission\Accommodation\AccommodationEmissionCalculator;
use App\Service\Emission\Accommodation\AccommodationEmissionRecordService;
use App\Service\Emission\Accommodation\AccommodationEmissionRequestMapper;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Accommodation\AccommodationFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class AccommodationEmissionControllerTest extends KernelTestCase
{
    private string $attachmentDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachmentDirectory = sys_get_temp_dir().'/bgfm-accommodation-attachments-'.bin2hex(random_bytes(8));
    }

    public function testHotelPreviewIsAuthoritativeAndCreatePersistsModernRecord(): void
    {
        $post = $this->hotelPost() + ['amount' => '999', 'emission' => '999', 'factorYear' => '1900'];
        $preview = $this->preview($post);
        $data = json_decode((string) $preview->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $preview->getStatusCode());
        self::assertSame('6', $data['normalizedAmount']);
        self::assertSame('57.303', $data['emissionKgCo2e']);
        self::assertSame(2024, $data['factorTraces'][0]['factorYear']);

        $context = $this->context();
        $request = $this->request('POST', $post + ['notes' => '  Hotel equipo  ']);
        $request->request->set('_token', $this->csrfToken('accommodation_emission_v1_create'));
        $persisted = null;
        $response = $this->create($request, $context, 1, $persisted);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame($context['category'], $persisted->getCategory());
        self::assertSame(6.0, $persisted->getAmount());
        self::assertSame(57.303, $persisted->getEmission());
        self::assertSame('Hotel equipo', $persisted->getNotes());
        self::assertStringContainsString('"version":"accommodation-v1"', (string) $persisted->getCalculationDetails());
        self::assertStringNotContainsString('999', (string) $persisted->getCalculationDetails());
    }

    public function testCreateSupportsHostelApartmentAndOtherContracts(): void
    {
        foreach ([
            [$this->hostelPost(), 6.0, 9.5505, EmissionRecord::STATUS_CALCULATED],
            [$this->apartmentPost(), 6.0, 24.522, EmissionRecord::STATUS_CALCULATED],
            [$this->otherPost(), null, null, EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE],
        ] as [$post, $amount, $emission, $status]) {
            $context = $this->context();
            $request = $this->request('POST', $post);
            $request->request->set('_token', $this->csrfToken('accommodation_emission_v1_create'));
            $persisted = null;

            $response = $this->create($request, $context, 1, $persisted);

            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
            self::assertInstanceOf(EmissionRecord::class, $persisted);
            self::assertSame($amount, $persisted->getAmount());
            self::assertSame($emission, $persisted->getEmission());
            self::assertSame($status, $persisted->getStatus());
        }
    }

    public function testCrossYearCreateIsRejectedWithoutPersistence(): void
    {
        $context = $this->context();
        $post = $this->hotelPost();
        $post['startDate'] = '2024-12-31';
        $post['endDate'] = '2025-01-01';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('accommodation_emission_v1_create'));
        $persisted = null;

        $response = $this->create($request, $context, 0, $persisted);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($persisted);
        self::assertStringContainsString('deben dividirse', (string) $response->getContent());
    }

    public function testEditRecalculatesAndReplacesSnapshotAuthority(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $oldSnapshot = $record->getCalculationDetails();
        $post = $this->hotelPost();
        $post['occupiedRooms'] = '4';
        $post['emission'] = '777';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('accommodation_emission_v1_edit_300'));

        $response = $this->edit($record, $request, $context, 1);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(12.0, $record->getAmount());
        self::assertSame(114.606, $record->getEmission());
        self::assertNotSame($oldSnapshot, $record->getCalculationDetails());
        self::assertStringContainsString('"occupiedRooms":"4"', (string) $record->getCalculationDetails());
        self::assertStringNotContainsString('777', (string) $record->getCalculationDetails());
    }

    public function testDuplicateUsesCreateRecalculationAndDoesNotCopyAttachments(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Duplicar estancia');
        $record->addAttachment((new EmissionRecordAttachment())
            ->setEmissionRecord($record)
            ->setOriginalName('no-copiar.pdf')
            ->setStoredName(str_repeat('a', 32).'.pdf')
            ->setMimeType('application/pdf')
            ->setSize(20)
            ->setCreatedAt(new \DateTimeImmutable()));

        $response = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new AccommodationEmissionSnapshot(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString('action="/backend/emission/new-accommodation-v1?', $content);
        self::assertStringContainsString('Duplicar estancia', $content);
        self::assertStringNotContainsString('no-copiar.pdf', $content);

        $post = $this->hotelPost();
        $post['occupiedRooms'] = '1';
        $post['nights'] = '2';
        $post['emission'] = '999';
        $request = $this->request('POST', $post, ['page' => '2']);
        $request->request->set('_token', $this->csrfToken('accommodation_emission_v1_create'));
        $duplicate = null;
        $createResponse = $this->create($request, $context, 1, $duplicate);

        self::assertSame(Response::HTTP_FOUND, $createResponse->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $duplicate);
        self::assertNotSame($record, $duplicate);
        self::assertSame(2.0, $duplicate->getAmount());
        self::assertSame(19.101, $duplicate->getEmission());
        self::assertCount(0, $duplicate->getAttachments());
        self::assertStringNotContainsString('999', (string) $duplicate->getCalculationDetails());
    }

    /** @param array<string, string> $post */
    private function preview(array $post): Response
    {
        $context = $this->context();
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('accommodation_emission_v1_preview'));

        return $this->controller()->preview(
            $request,
            $context['active'],
            new AccommodationEmissionRequestMapper(),
            $this->calculator(),
        );
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Alojamientos')->setType('rodaje')->setCountry('ES');
        $this->setEntityId($project, 10);
        $category = (new Category())->setName('Alojamientos');
        $this->setEntityId($category, 20);
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2022-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $project->addPhaseDate($phase);

        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phase);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    /** @param array<string, mixed> $context */
    private function create(Request $request, array $context, int $persistCalls, ?EmissionRecord &$persisted): Response
    {
        return $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new AccommodationEmissionRequestMapper(),
            $this->recordService($persistCalls, $persisted),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager(),
        );
    }

    /** @param array<string, mixed> $context */
    private function edit(EmissionRecord $record, Request $request, array $context, int $persistCalls): Response
    {
        $ignored = null;

        return $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new AccommodationEmissionRequestMapper(),
            $this->recordService($persistCalls, $ignored),
            new AccommodationEmissionSnapshot(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager(),
        );
    }

    private function controller(): AccommodationEmissionController
    {
        $controller = new AccommodationEmissionController();
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
        $request->attributes->set('_route', 'backend_emission_new_accommodation_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): AccommodationEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new AccommodationEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('accommodation', $categoryKey);
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('accommodation' === $categoryKey
                        && EmissionFactor::TEMPORAL_TYPE_VERSIONED === $temporalType
                        && EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()
                        && $factor->getFunctionalKey() === $functionalKey
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new AccommodationEmissionCalculator(
            new AccommodationFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): AccommodationEmissionRecordService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                if ($entity instanceof EmissionRecord) {
                    $persisted = $entity;
                    if (null === $entity->getId()) {
                        $this->setEntityId($entity, 301);
                    }
                }
            },
        );
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return new AccommodationEmissionRecordService($this->calculator(), new AccommodationEmissionSnapshot(), $entityManager);
    }

    private function attachmentEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    /** @param array<string, mixed> $context */
    private function record(array $context, ?string $notes = null): EmissionRecord
    {
        $request = $this->request('POST', $this->hotelPost());
        $input = (new AccommodationEmissionRequestMapper())->map($request);
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])
            ->setPhase($context['phase'])
            ->setCategory($context['category'])

            ->setAmount((float) $result->normalizedAmount)
            ->setEmission((float) $result->emissionKgCo2e)
            ->setStatus($result->status)
            ->setRegisteredAt(new \DateTimeImmutable('2024-06-01'))
            ->setNotes($notes)
            ->setCalculationDetails((new AccommodationEmissionSnapshot())->encode($input, $result));
        $this->setEntityId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function hotelPost(): array
    {
        return ['startDate' => '2024-06-01', 'endDate' => '2024-06-30', 'country' => 'ESP', 'accommodationType' => 'hotel', 'stars' => '4', 'occupiedRooms' => '2', 'nights' => '3', 'people' => '4'];
    }

    /** @return array<string, string> */
    private function hostelPost(): array
    {
        return ['startDate' => '2024-06-01', 'endDate' => '2024-06-30', 'country' => 'ESP', 'accommodationType' => 'hostel', 'people' => '2', 'nights' => '3'];
    }

    /** @return array<string, string> */
    private function apartmentPost(): array
    {
        return ['startDate' => '2024-06-01', 'endDate' => '2024-06-30', 'country' => 'ESP', 'accommodationType' => 'apartment', 'people' => '3', 'nights' => '2'];
    }

    /** @return array<string, string> */
    private function otherPost(): array
    {
        return ['startDate' => '2024-06-01', 'endDate' => '2024-06-30', 'country' => 'ESP', 'accommodationType' => 'other'];
    }

    private function setEntityId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }
}
