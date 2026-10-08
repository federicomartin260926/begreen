<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\CateringEmissionController;
use App\DataFixtures\CateringEmissionFactorFixtures;
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
use App\Service\Emission\Catering\CateringEmissionCalculator;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringEmissionRecordService;
use App\Service\Emission\Catering\CateringEmissionRequestMapper;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Catering\CateringFactorResolver;
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

final class CateringEmissionControllerTest extends KernelTestCase
{
    public function testPreviewReturnsOfficialCat001(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->mealPost());
        $request->request->set('_preview_token', $this->csrfToken('catering_emission_v1_preview'));

        $response = $this->controller()->preview($request, $context['active'], new CateringEmissionRequestMapper(), $this->calculator());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('62.2355395', $data['emissionKgCo2e']);
        self::assertSame('MENU_VEGAN', $data['factorTraces'][0]['factorId']);
        self::assertSame('VERSIONED', $data['factorTraces'][0]['temporalType']);
        self::assertSame('AGRIBALYSE 3.2', $data['factorTraces'][0]['factorVersion']);
        self::assertNull($data['factorTraces'][0]['factorYear']);
        self::assertSame('TABLEWARE_COMPOSTABLE_MENU_PACK', $data['factorTraces'][1]['factorId']);
        self::assertSame('COMPOSITE', $data['factorTraces'][1]['temporalType']);
        self::assertNull($data['factorTraces'][1]['factorYear']);
    }

    public function testPreviewExposesReusableProxyLcaTrace(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->mealPost('reusable'));
        $request->request->set('_preview_token', $this->csrfToken('catering_emission_v1_preview'));

        $response = $this->controller()->preview($request, $context['active'], new CateringEmissionRequestMapper(), $this->calculator());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('TABLEWARE_REUSABLE_MENU_SERVICE', $data['factorTraces'][1]['factorId']);
        self::assertSame('PROXY_LCA', $data['factorTraces'][1]['temporalType']);
        self::assertNull($data['factorTraces'][1]['activityYear'] ?? null);
        self::assertNull($data['factorTraces'][1]['factorYear']);
    }

    public function testCreatePersistsModernBackendCalculation(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->mealPost() + ['amount' => '999', 'emission' => '999']);
        $request->request->set('_token', $this->csrfToken('catering_emission_v1_create'));
        $persisted = null;

        $response = $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new CateringEmissionRequestMapper(),
            $this->recordService(1, $persisted),
            new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-catering-test'),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame($context['category'], $persisted->getCategory());
        self::assertSame($context['phase'], $persisted->getPhase());
        self::assertSame(100.0, $persisted->getAmount());
        self::assertSame(62.2355395, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());
        self::assertStringContainsString('"version":"catering-v1"', (string) $persisted->getCalculationDetails());
        self::assertStringNotContainsString('999', (string) $persisted->getCalculationDetails());
    }

    public function testEditRecalculatesWithoutUsingPreviousEmission(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $post = $this->mealPost('reusable');
        $post['preparedCount'] = ['50'];
        $post['consumedCount'] = ['40'];
        $post['emission'] = '777';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('catering_emission_v1_edit_300'));
        $ignored = null;

        $response = $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new CateringEmissionRequestMapper(),
            $this->recordService(1, $ignored),
            new CateringEmissionSnapshot(),
            new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-catering-test'),
            $this->attachmentManager(),
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(300, $record->getId());
        self::assertSame(50.0, $record->getAmount());
        self::assertSame(26.85841975, $record->getEmission());
        self::assertStringNotContainsString('777', (string) $record->getCalculationDetails());
    }

    public function testDuplicateLoadsInputsAndDoesNotCopyAttachments(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Duplicar catering');
        $record->addAttachment((new EmissionRecordAttachment())->setEmissionRecord($record)->setOriginalName('no-copiar.pdf')->setStoredName(str_repeat('a', 32).'.pdf')->setMimeType('application/pdf')->setSize(10)->setCreatedAt(new \DateTimeImmutable()));

        $response = $this->controller()->duplicate($record, $this->request('GET', query: ['page' => '2']), $context['active'], $context['categories'], new CateringEmissionSnapshot());
        $content = (string) $response->getContent();

        self::assertStringContainsString('action="/backend/emission/new-catering-v1?', $content);
        self::assertStringContainsString('value="vegan" selected', $content);
        self::assertStringContainsString('Duplicar catering', $content);
        self::assertStringNotContainsString('no-copiar.pdf', $content);
        foreach (['breakfast', 'coffeebreak', 'snack', 'meal', 'sandwich', 'water', 'drink', 'coffee', 'gas'] as $activityType) {
            self::assertStringContainsString(sprintf('value="%s"', $activityType), $content);
        }
        foreach (['people', 'menuVariant[]', 'preparedCount[]', 'consumedCount[]', 'tablewareType', 'sandwichType', 'containerVolumeLiters', 'description', 'serviceCount', 'gasType'] as $field) {
            self::assertStringContainsString(sprintf('name="%s"', $field), $content);
        }
        self::assertSame([
            '' => 'Seleccione…',
            'Vacuno' => 'Vacuno',
            'Pollo' => 'Pollo',
            'Cerdo / jamón' => 'Cerdo/jamón',
            'Pescado / atún' => 'Pescado/atún',
            'Vegetariano' => 'Vegetariano',
            'Vegano / hummus' => 'Vegano/hummus',
        ], $this->selectOptionLabels($content, 'sandwichType'));
        self::assertSame([
            '' => 'Seleccione…',
            '0.33' => '0,33 L',
            '0.5' => '0,5 L',
            '1' => '1 L',
            '1.5' => '1,5 L',
            '2' => '2 L',
            '5' => '5 L',
            '8' => '8 L',
            '20' => '20 L',
        ], $this->selectOptionLabels($content, 'containerVolumeLiters'));
        self::assertSame([
            '' => 'Seleccione…',
            'PET' => 'PET',
            'rPET 75%' => 'rPET 75 %',
            'REUSABLE' => 'Bidón reutilizable',
        ], $this->selectOptionLabels($content, 'containerMaterial'));
        self::assertSame([
            '' => 'Seleccione…',
            'Tradicional' => 'Tradicional',
            'Cápsula compostable' => 'Cápsula compostable',
            'Cápsula aluminio' => 'Cápsula de aluminio',
            'Cápsula plástico' => 'Cápsula de plástico',
        ], $this->selectOptionLabels($content, 'coffeeType'));
        $englishResponse = $this->controller()->duplicate($record, $this->request('GET', locale: 'en'), $context['active'], $context['categories'], new CateringEmissionSnapshot());
        $englishContent = (string) $englishResponse->getContent();
        self::assertSame([
            '' => 'Select…',
            'Vacuno' => 'Beef',
            'Pollo' => 'Chicken',
            'Cerdo / jamón' => 'Pork/ham',
            'Pescado / atún' => 'Fish/tuna',
            'Vegetariano' => 'Vegetarian',
            'Vegano / hummus' => 'Vegan/hummus',
        ], $this->selectOptionLabels($englishContent, 'sandwichType'));
        self::assertSame([
            '' => 'Select…',
            '0.33' => '0.33 L',
            '0.5' => '0.5 L',
            '1' => '1 L',
            '1.5' => '1.5 L',
            '2' => '2 L',
            '5' => '5 L',
            '8' => '8 L',
            '20' => '20 L',
        ], $this->selectOptionLabels($englishContent, 'containerVolumeLiters'));
        self::assertSame([
            '' => 'Select…',
            'PET' => 'PET',
            'rPET 75%' => '75% rPET',
            'REUSABLE' => 'Reusable water container',
        ], $this->selectOptionLabels($englishContent, 'containerMaterial'));
        self::assertSame([
            '' => 'Select…',
            'Tradicional' => 'Traditional',
            'Cápsula compostable' => 'Compostable capsule',
            'Cápsula aluminio' => 'Aluminium capsule',
            'Cápsula plástico' => 'Plastic capsule',
        ], $this->selectOptionLabels($englishContent, 'coffeeType'));
        self::assertStringNotContainsString('name="department"', $content);
        self::assertStringNotContainsString('name="person_role"', $content);
    }

    public function testCleanCreateDoesNotPreselectStructuredOptionsInSpanishOrEnglish(): void
    {
        $context = $this->context();

        foreach (['es', 'en'] as $locale) {
            $ignored = null;
            $response = $this->controller()->create(
                $this->request('GET', locale: $locale),
                $context['active'],
                $context['categories'],
                $context['projects'],
                new CateringEmissionRequestMapper(),
                $this->recordService(0, $ignored),
                new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-catering-test'),
                $this->attachmentManager(),
            );
            $content = (string) $response->getContent();

            foreach (['sandwichType', 'containerVolumeLiters', 'containerMaterial', 'coffeeType'] as $field) {
                self::assertSame([], $this->selectedOptionValues($content, $field));
            }
        }
    }

    public function testDuplicatePreservesHistoricalFreeFormValuesInStructuredSelectors(): void
    {
        $context = $this->context();
        $cases = [
            [new CateringEmissionInput(
                new \DateTimeImmutable('2025-01-01'),
                new \DateTimeImmutable('2025-01-02'),
                'ESP',
                CateringEmissionInput::TYPE_SANDWICH,
                sandwichType: 'Receta histórica',
                preparedCount: '2',
            ), 'sandwichType', 'Receta histórica'],
            [new CateringEmissionInput(
                new \DateTimeImmutable('2025-01-01'),
                new \DateTimeImmutable('2025-01-02'),
                'ESP',
                CateringEmissionInput::TYPE_WATER,
                containerVolumeLiters: '0.75',
                containerMaterial: 'Vidrio retornable',
                containerCount: '2',
            ), 'containerVolumeLiters', '0.75'],
            [new CateringEmissionInput(
                new \DateTimeImmutable('2025-01-01'),
                new \DateTimeImmutable('2025-01-02'),
                'ESP',
                CateringEmissionInput::TYPE_WATER,
                containerVolumeLiters: '1',
                containerMaterial: 'Vidrio retornable',
                containerCount: '2',
            ), 'containerMaterial', 'Vidrio retornable'],
            [new CateringEmissionInput(
                new \DateTimeImmutable('2025-01-01'),
                new \DateTimeImmutable('2025-01-02'),
                'ESP',
                CateringEmissionInput::TYPE_COFFEE,
                serviceCount: '2',
                coffeeType: 'Cafetera italiana',
            ), 'coffeeType', 'Cafetera italiana'],
        ];

        foreach ($cases as $index => [$input, $field, $historicalValue]) {
            $record = $this->recordFromInput($context, $input, 310 + $index);
            $response = $this->controller()->duplicate($record, $this->request('GET'), $context['active'], $context['categories'], new CateringEmissionSnapshot());
            $content = (string) $response->getContent();

            self::assertSame([$historicalValue], $this->selectedOptionValues($content, $field));
            self::assertArrayHasKey($historicalValue, $this->selectOptionLabels($content, $field));

            $ignored = null;
            $editResponse = $this->controller()->edit(
                $record,
                $this->request('GET'),
                $context['active'],
                $context['categories'],
                $context['projects'],
                new CateringEmissionRequestMapper(),
                $this->recordService(0, $ignored),
                new CateringEmissionSnapshot(),
                new EmissionRecordAttachmentStorage(sys_get_temp_dir().'/bgfm-catering-test'),
                $this->attachmentManager(),
            );

            self::assertSame([$historicalValue], $this->selectedOptionValues((string) $editResponse->getContent(), $field));
        }
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Catering')->setType('rodaje')->setCountry('ES');
        $this->setId($project, 10);
        $category = (new Category())->setName('Catering');
        $this->setId($category, 20);
        $phase = (new ProjectPhaseDate())->setProject($project)->setPhase('actividad')->setStartDate(new \DateTimeImmutable('2022-01-01'))->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $project->addPhaseDate($phase);
        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phase);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    private function controller(): CateringEmissionController
    {
        $controller = new CateringEmissionController();
        $controller->setContainer(self::getContainer());
        $user = (new \App\Entity\User())->setName('Admin')->setSurnames('User')->setEmail('admin@example.test')->setPassword('password')->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = [], string $locale = 'es'): Request
    {
        $request = new Request($query, $post, [], [], [], ['REQUEST_METHOD' => $method]);
        $request->setLocale($locale);
        self::getContainer()->get('translator')->setLocale($locale);
        $request->attributes->set('_route', 'backend_emission_new_catering_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): CateringEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void { if ($factor instanceof EmissionFactor) { $factors[] = $factor; } });
        (new CateringEmissionFactorFixtures($keyGenerator))->load($manager);
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findMethodological')->willReturnCallback(static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
            foreach ($factors as $factor) {
                if ('catering' === $categoryKey && $functionalKey === $factor->getFunctionalKey() && $temporalType === $factor->getTemporalType()) { return $factor; }
            }
            return null;
        });

        return new CateringEmissionCalculator(new CateringFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)));
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): CateringEmissionRecordService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            if ($entity instanceof EmissionRecord) { $persisted = $entity; if (null === $entity->getId()) { $this->setId($entity, 301); } }
        });
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return new CateringEmissionRecordService($this->calculator(), new CateringEmissionSnapshot(), $entityManager);
    }

    private function attachmentManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    /** @param array<string, mixed> $context */
    private function record(array $context, ?string $notes = null): EmissionRecord
    {
        $input = (new CateringEmissionRequestMapper())->map($this->request('POST', $this->mealPost()));
        $record = $this->recordFromInput($context, $input, 300);
        $record->setNotes($notes);

        return $record;
    }

    /** @param array<string, mixed> $context */
    private function recordFromInput(array $context, CateringEmissionInput $input, int $id): EmissionRecord
    {
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])
            ->setPhase($context['phase'])
            ->setCategory($context['category'])
            ->setAmount(null === $result->normalizedAmount ? null : (float) $result->normalizedAmount)
            ->setEmission(null === $result->emissionKgCo2e ? null : (float) $result->emissionKgCo2e)
            ->setStatus($result->status)
            ->setRegisteredAt(new \DateTimeImmutable('2025-01-01'))
            ->setCalculationDetails((new CateringEmissionSnapshot())->encode($input, $result));
        $this->setId($record, $id);

        return $record;
    }

    /** @return array<string, string> */
    private function selectOptionLabels(string $content, string $field): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $options = [];
        foreach ($xpath->query(sprintf('//select[@name="%s"]/option', $field)) ?: [] as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }

    /** @return list<string> */
    private function selectedOptionValues(string $content, string $field): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $values = [];
        foreach ($xpath->query(sprintf('//select[@name="%s"]/option[@selected]', $field)) ?: [] as $option) {
            $values[] = $option->getAttribute('value');
        }

        return $values;
    }

    /** @return array<string, string|list<string>> */
    private function mealPost(string $tableware = 'compostable'): array
    {
        return ['startDate' => '2025-01-01', 'endDate' => '2025-01-02', 'country' => 'ESP', 'activityType' => 'meal', 'menuVariant' => ['vegan'], 'preparedCount' => ['100'], 'consumedCount' => ['90'], 'tablewareType' => $tableware];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }
}
