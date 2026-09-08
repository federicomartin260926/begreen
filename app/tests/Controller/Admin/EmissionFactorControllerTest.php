<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\EmissionFactorController;
use App\Entity\EmissionFactor;
use App\Entity\User;
use App\Form\EmissionFactorType;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class EmissionFactorControllerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private EmissionFactorRepository $repository;
    private EmissionFactorKeyGenerator $keyGenerator;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
        $this->repository = $container->get(EmissionFactorRepository::class);
        $this->keyGenerator = $container->get(EmissionFactorKeyGenerator::class);
        $this->session = new Session(new MockArraySessionStorage());

        $admin = (new User())
            ->setName('Factor')
            ->setSurnames('Admin')
            ->setEmail('factor.admin@example.test')
            ->setPassword('not-used')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $container->get('twig')->addGlobal('userProjects', []);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        self::ensureKernelShutdown();
    }

    public function testAdminCanListAndFilterFactorsWithoutLoadingAnUnfilteredCatalogue(): void
    {
        $this->persistFactor('admin-filter-a', ['kind' => 'wanted'], EmissionFactor::TEMPORAL_TYPE_ANNUAL, 2026, null, 'TEST SOURCE');
        $this->persistFactor('admin-filter-b', ['kind' => 'other'], EmissionFactor::TEMPORAL_TYPE_VERSIONED, null, '0', 'OTHER SOURCE');
        $request = $this->request('/admin/emission-factors/', 'GET', [
            'categoryKey' => 'admin-filter-a',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            'year' => '2026',
            'source' => 'TEST',
        ], 'admin_emission_factor_index');

        $response = $this->invoke($request, fn (): Response => $this->controller()->index($request, $this->repository));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Catálogo de factores de emisión', $content);
        self::assertStringContainsString('TEST SOURCE', $content);
        self::assertStringNotContainsString('OTHER SOURCE', $content);
        self::assertStringContainsString('NULL', $content);
        self::assertStringContainsString('/admin/emission-factors/', $content);
    }

    public function testCreateAndEditPreserveJsonAndDifferentiateNullFromZero(): void
    {
        $this->persistFactor('admin-form', ['seed' => true], EmissionFactor::TEMPORAL_TYPE_VERSIONED, null, '1', 'SEED');
        $createRequest = $this->formRequest('/admin/emission-factors/new', 'admin_emission_factor_new', [
            'categoryKey' => 'admin-form',
            'criteria' => '{"unknown":{"nested":true},"amount":0}',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            'year' => '',
            'value' => '',
            'unit' => 'kgCO2e/unit',
            'source' => 'ADMIN CREATE',
            'sourceDetail' => 'Detail',
            'metadata' => '{"publication":{"year":2026}}',
        ]);

        $response = $this->invoke($createRequest, fn (): Response => $this->controller()->new($createRequest, $this->repository, $this->entityManager, self::getContainer()->get('translator')));
        self::assertTrue($response->isRedirect());
        $created = $this->repository->findOneBy(['source' => 'ADMIN CREATE']);
        self::assertInstanceOf(EmissionFactor::class, $created);
        self::assertSame($this->keyGenerator->generate($created->getCriteria()), $created->getFunctionalKey());
        self::assertSame(['unknown' => ['nested' => true], 'amount' => 0], $created->getCriteria());
        self::assertSame(['publication' => ['year' => 2026]], $created->getMetadata());
        self::assertNull($created->getValue());
        self::assertNull($created->getYear());

        $editRequest = $this->formRequest('/admin/emission-factors/'.$created->getId().'/edit', 'admin_emission_factor_edit', [
            'categoryKey' => 'admin-form',
            'criteria' => '{"amount":0,"unknown":{"nested":true}}',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            'year' => '',
            'value' => '0',
            'unit' => 'kgCO2e/item',
            'source' => 'ADMIN EDIT',
            'sourceDetail' => '',
            'metadata' => '',
        ]);
        $response = $this->invoke($editRequest, fn (): Response => $this->controller()->edit($editRequest, $created, $this->repository, $this->entityManager, self::getContainer()->get('translator')));

        self::assertTrue($response->isRedirect());
        self::assertNotNull($created->getValue());
        self::assertSame(0.0, (float) $created->getValue());
        self::assertSame('kgCO2e/item', $created->getUnit());
        self::assertNull($created->getMetadata());
    }

    public function testInvalidJsonAndAnnualWithoutYearAreRejected(): void
    {
        $factor = (new EmissionFactor())
            ->setCategoryKey('admin-invalid')
            ->setFunctionalKey('')
            ->setCriteria([])
            ->setUnit('')
            ->setSource('');
        $form = self::getContainer()->get('form.factory')->create(EmissionFactorType::class, $factor, [
            'csrf_protection' => false,
            'category_keys' => ['admin-invalid'],
        ]);
        $form->submit([
            'categoryKey' => 'admin-invalid',
            'criteria' => '{invalid',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            'year' => '',
            'value' => '1.25',
            'unit' => 'kgCO2e/unit',
            'source' => 'INVALID',
            'sourceDetail' => '',
            'metadata' => '',
        ]);
        self::assertFalse($form->isValid());
        self::assertStringContainsString('Introduce un objeto JSON válido.', (string) $form->getErrors(true));

        $factor = (new EmissionFactor())
            ->setCategoryKey('admin-invalid')
            ->setFunctionalKey('')
            ->setCriteria([])
            ->setUnit('')
            ->setSource('');
        $form = self::getContainer()->get('form.factory')->create(EmissionFactorType::class, $factor, [
            'csrf_protection' => false,
            'category_keys' => ['admin-invalid'],
        ]);
        $form->submit([
            'categoryKey' => 'admin-invalid',
            'criteria' => '{"kind":"annual"}',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            'year' => '',
            'value' => '1.25',
            'unit' => 'kgCO2e/unit',
            'source' => 'INVALID',
            'sourceDetail' => '',
            'metadata' => '',
        ]);
        self::assertFalse($form->isValid());
        $errors = (string) $form->getErrors(true);
        self::assertStringContainsString('Los factores ANNUAL necesitan un año.', $errors);
    }

    public function testDeleteRequiresCsrfAndNavigationPointsToTheModernCatalogue(): void
    {
        $factor = $this->persistFactor('admin-delete', ['delete' => true], EmissionFactor::TEMPORAL_TYPE_COMPOSITE, null, null, 'DELETE ME');
        $id = $factor->getId();
        $invalidRequest = $this->request('/admin/emission-factors/'.$id.'/delete', 'POST', ['_token' => 'invalid'], 'admin_emission_factor_delete');
        $response = $this->invoke($invalidRequest, fn (): Response => $this->controller()->delete($invalidRequest, $factor, $this->entityManager));
        self::assertTrue($response->isRedirect());
        self::assertNotNull($this->repository->find($id));

        $validRequest = $this->request('/admin/emission-factors/'.$id.'/delete', 'POST', [], 'admin_emission_factor_delete');
        $response = $this->invoke($validRequest, function () use ($validRequest, $factor): Response {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('delete_emission_factor_'.$factor->getId());
            $validRequest->request->set('_token', $token->getValue());

            return $this->controller()->delete($validRequest, $factor, $this->entityManager);
        });
        self::assertTrue($response->isRedirect());
        self::assertNull($this->repository->find($id));

        $navigation = file_get_contents(__DIR__.'/../../../templates/base_backend.html.twig');
        self::assertIsString($navigation);
        self::assertStringContainsString("path('admin_emission_factor_index'", $navigation);
        self::assertStringNotContainsString('admin_emission_activity', $navigation);
    }

    public function testEditRejectsCriteriaThatCollideWithAnotherFactorIdentity(): void
    {
        $existing = $this->persistFactor('admin-collision', ['kind' => 'existing'], EmissionFactor::TEMPORAL_TYPE_ANNUAL, 2026, '1', 'COLLISION TARGET');
        $edited = $this->persistFactor('admin-collision', ['kind' => 'edited'], EmissionFactor::TEMPORAL_TYPE_ANNUAL, 2026, '2', 'COLLISION EDIT');
        $originalKey = $edited->getFunctionalKey();
        $request = $this->formRequest('/admin/emission-factors/'.$edited->getId().'/edit', 'admin_emission_factor_edit', [
            'categoryKey' => 'admin-collision',
            'criteria' => '{"kind":"existing"}',
            'temporalType' => EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            'year' => '2026',
            'value' => '2',
            'unit' => 'kgCO2e/unit',
            'source' => 'COLLISION EDIT',
            'sourceDetail' => '',
            'metadata' => '',
        ]);
        $request->attributes->set('_route_params', ['id' => $edited->getId()]);

        $response = $this->invoke($request, fn (): Response => $this->controller()->edit($request, $edited, $this->repository, $this->entityManager, self::getContainer()->get('translator')));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ya existe un factor de emisión con estos criterios para esta categoría y año.', (string) $response->getContent());
        self::assertSame($existing->getFunctionalKey(), $edited->getFunctionalKey());
        $storedKey = $this->connection->fetchOne('SELECT functional_key FROM emission_factor WHERE id = ?', [$edited->getId()]);
        self::assertSame($originalKey, $storedKey);
    }

    /** @param array<string, string> $data */
    private function formRequest(string $path, string $route, array $data): Request
    {
        $request = $this->request($path, 'POST', [], $route);
        $this->invoke($request, function () use ($request, $data): Response {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('emission_factor');
            $request->request->set('emission_factor', $data + ['_token' => $token->getValue()]);

            return new Response();
        });

        return $request;
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $path, string $method, array $parameters, string $route): Request
    {
        $request = Request::create($path, $method, $parameters);
        $request->setLocale('es');
        $request->setSession($this->session);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', []);

        return $request;
    }

    private function invoke(Request $request, callable $callback): Response
    {
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        try {
            return $callback();
        } finally {
            $requestStack->pop();
        }
    }

    private function controller(): EmissionFactorController
    {
        $controller = new EmissionFactorController();
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    /** @param array<string, mixed> $criteria */
    private function persistFactor(string $categoryKey, array $criteria, string $temporalType, ?int $year, ?string $value, string $source): EmissionFactor
    {
        $factor = (new EmissionFactor())
            ->setCategoryKey($categoryKey)
            ->setFunctionalKey($this->keyGenerator->generate($criteria))
            ->setCriteria($criteria)
            ->setTemporalType($temporalType)
            ->setYear($year)
            ->setValue($value)
            ->setUnit('kgCO2e/unit')
            ->setSource($source);
        $this->entityManager->persist($factor);
        $this->entityManager->flush();

        return $factor;
    }
}
