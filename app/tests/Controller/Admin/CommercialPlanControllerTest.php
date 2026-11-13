<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CommercialPlanController;
use App\Entity\CommercialPlan;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Form\CommercialPlanType;
use App\Form\CommercialTierType;
use App\Service\ProjectCommercialPricing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CommercialPlanControllerTest extends KernelTestCase
{
    private ?\Doctrine\DBAL\Connection $connection = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;
        self::ensureKernelShutdown();
    }

    public function testEditMapperUpdatesCommercialFeatures(): void
    {
        $container = self::getContainer();
        $plan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('pro')
            ->setName('Elaboración Pro')
            ->setDescription('Plan de Elaboración.')
            ->setPriceAmount(4900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_standard_test')
            ->setStripeUpgradeFromStandardPriceId('price_upgrade_test')
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(2)
            ->setFeatures([
                'allowed_scores' => [3, 4, 5],
                'sustainability_plan.department_pdf' => true,
                'sustainability_plan.export.department_pdf' => true,
                'sustainability_plan.watermark_free_pdf' => true,
                'sustainability_plan.history' => true,
                'sustainability_plan.advanced_exports' => false,
                'sustainability_plan.export.category' => false,
                'sustainability_plan.export.department' => false,
                'sustainability_plan.export.impact_area' => false,
                'sustainability_plan.export.triple_balance' => false,
                'sustainability_plan.export.ods' => false,
                'sustainability_plan.export.excel' => false,
                'sustainability_plan.internal_notes' => false,
                'sustainability_plan.responsibles' => false,
                'sustainability_plan.checklist' => false,
                'sustainability_plan.custom_measures' => false,
                'sustainability_plan.validation_summary' => false,
                'sustainability_plan.branding' => false,
            ]);

        $form = $container->get('form.factory')->create(CommercialPlanType::class, $plan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => true,
        ]);
        $form->submit([
            'name' => 'Standard Plus',
            'description' => 'Plan actualizado',
            'priceAmount' => '12.34',
            'priceCurrency' => 'eur',
            'stripePriceId' => 'price_standard_live',
            'stripeUpgradeFromStandardPriceId' => 'price_upgrade_live',
            'maxEvidenceCount' => 25,
            'watermarkEnabled' => true,
            'active' => false,
            'sortOrder' => 7,
            'allowedScores' => ['5', '2', '5', '1'],
            'pdfByDepartments' => true,
            'advancedExports' => true,
            'emailExport' => true,
            'internalNotes' => true,
            'responsibles' => true,
            'checklist' => true,
            'customMeasures' => true,
            'validationSummary' => true,
            'branding' => true,
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());

        $controller = new CommercialPlanController();
        $controller->setContainer($container);

        $reflection = new \ReflectionMethod($controller, 'applyEditableValues');
        $reflection->setAccessible(true);
        $reflection->invoke($controller, $plan, $form);

        self::assertSame('Standard Plus', $plan->getName());
        self::assertSame('Plan actualizado', $plan->getDescription());
        self::assertSame(1234, $plan->getPriceAmount());
        self::assertSame('EUR', $plan->getPriceCurrency());
        self::assertSame('price_standard_live', $plan->getStripePriceId());
        self::assertSame('price_upgrade_live', $plan->getStripeUpgradeFromStandardPriceId());
        self::assertSame(25, $plan->getMaxEvidenceCount());
        self::assertTrue($plan->isWatermarkEnabled());
        self::assertFalse($plan->isActive());
        self::assertSame(7, $plan->getSortOrder());
        self::assertSame([1, 2, 5], $plan->getAllowedScores());
        self::assertTrue($plan->getFeature('sustainability_plan.department_pdf', false));
        self::assertTrue($plan->getFeature('sustainability_plan.export.department_pdf', false));
        self::assertTrue($plan->getFeature('sustainability_plan.advanced_exports', false));
        self::assertTrue($plan->getFeature('sustainability_plan.export.email', false));
    }

    public function testUnifiedTierFormIncludesBothPhasesWithoutEditingPricesOrStripe(): void
    {
        $container = self::getContainer();
        $elaboration = $this->makePlan(CommercialPhase::ELABORATION, 'pro', 4900);
        $implementation = $this->makePlan(CommercialPhase::IMPLEMENTATION, 'pro', 8000);
        $implementation->setStripePriceId('price_implementation_existing');

        $form = $container->get('form.factory')->create(CommercialTierType::class, null, [
            'csrf_protection' => false,
            'elaboration_plan' => $elaboration,
            'implementation_plan' => $implementation,
        ]);

        foreach (['elaboration', 'implementation'] as $phase) {
            self::assertTrue($form->has($phase));
            self::assertTrue($form->get($phase)->has('allowedScores'));
            self::assertTrue($form->get($phase)->has('pdfByDepartments'));
            self::assertFalse($form->get($phase)->has('priceAmount'));
            self::assertFalse($form->get($phase)->has('stripePriceId'));
            self::assertFalse($form->get($phase)->has('active'));
            self::assertFalse($form->get($phase)->has('sortOrder'));
        }

        $form->submit([
            'name' => 'Pro renovado',
            'active' => true,
            'sortOrder' => 3,
            'elaboration' => [
                'description' => 'Elaboración renovada',
                'maxEvidenceCount' => 20,
                'allowedScores' => ['1', '2', '5'],
                'pdfByDepartments' => true,
                'advancedExports' => true,
            ],
            'implementation' => [
                'description' => 'Implementación renovada',
                'maxEvidenceCount' => 4,
                'allowedScores' => ['4', '5'],
                'pdfByDepartments' => false,
                'advancedExports' => false,
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());

        $controller = new CommercialPlanController();
        $shared = new \ReflectionMethod($controller, 'applySharedValues');
        $shared->invoke($controller, $elaboration, $implementation, $form);
        $reflection = new \ReflectionMethod($controller, 'applyEditableValues');
        $reflection->invoke($controller, $elaboration, $form->get('elaboration'));
        $reflection->invoke($controller, $implementation, $form->get('implementation'));

        self::assertSame('Pro renovado', $elaboration->getName());
        self::assertSame('Pro renovado', $implementation->getName());
        self::assertTrue($elaboration->isActive());
        self::assertTrue($implementation->isActive());
        self::assertSame(3, $elaboration->getSortOrder());
        self::assertSame(3, $implementation->getSortOrder());
        self::assertSame('Elaboración renovada', $elaboration->getDescription());
        self::assertSame('Implementación renovada', $implementation->getDescription());
        self::assertSame([1, 2, 5], $elaboration->getAllowedScores());
        self::assertSame([4, 5], $implementation->getAllowedScores());
        self::assertTrue($elaboration->getFeature('sustainability_plan.department_pdf'));
        self::assertFalse($implementation->getFeature('sustainability_plan.department_pdf'));
        self::assertSame(4900, $elaboration->getPriceAmount());
        self::assertSame(8000, $implementation->getPriceAmount());
        self::assertSame('price_implementation_existing', $implementation->getStripePriceId());
    }

    public function testEditFormOnlyShowsStripeUpgradePriceForProPlans(): void
    {
        $container = self::getContainer();
        $formFactory = $container->get('form.factory');

        $basicPlan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('basic')
            ->setName('Elaboración Basic')
            ->setDescription('Plan gratuito de Elaboración.')
            ->setPriceAmount(0)
            ->setPriceCurrency('EUR')
            ->setStripePriceId(null)
            ->setStripeUpgradeFromStandardPriceId(null)
            ->setMaxEvidenceCount(10)
            ->setWatermarkEnabled(true)
            ->setActive(true)
            ->setSortOrder(1)
            ->setFeatures([]);

        $standardPlan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('standard')
            ->setName('Elaboración Standard')
            ->setDescription('Plan estándar de Elaboración.')
            ->setPriceAmount(2900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_standard_test')
            ->setStripeUpgradeFromStandardPriceId(null)
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(2)
            ->setFeatures([]);

        $proPlan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('pro')
            ->setName('Elaboración Pro')
            ->setDescription('Plan Pro de Elaboración.')
            ->setPriceAmount(4900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_pro_test')
            ->setStripeUpgradeFromStandardPriceId('price_upgrade_live')
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(3)
            ->setFeatures([]);

        $basicForm = $formFactory->create(CommercialPlanType::class, $basicPlan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => false,
        ])->createView();
        $standardForm = $formFactory->create(CommercialPlanType::class, $standardPlan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => false,
        ])->createView();
        $proForm = $formFactory->create(CommercialPlanType::class, $proPlan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => true,
        ])->createView();

        self::assertArrayNotHasKey('stripeUpgradeFromStandardPriceId', $basicForm->children);
        self::assertArrayNotHasKey('stripeUpgradeFromStandardPriceId', $standardForm->children);
        self::assertArrayHasKey('stripeUpgradeFromStandardPriceId', $proForm->children);
    }

    public function testEditFormRendersTranslatedContentInSpanish(): void
    {
        $html = $this->renderCommercialPlanForm('es', 'Elaboración Basic', CommercialPhase::ELABORATION, 2900);

        self::assertStringNotContainsString('backend.commercial_plans.form.', $html);
        self::assertStringContainsString('Editar plan comercial', $html);
        self::assertStringContainsString('Configuración única del nivel comercial', $html);
        self::assertStringContainsString('Nombre', $html);
        self::assertStringContainsString('Límite de evidencias', $html);
        self::assertStringContainsString('Elaboración', $html);
        self::assertStringContainsString('Implementación', $html);
        self::assertStringContainsString('Próximamente', $html);
        self::assertStringNotContainsString('Stripe Price ID', $html);
        self::assertStringContainsString('Gratis', $html);
    }

    public function testEditFormRendersTranslatedContentInEnglish(): void
    {
        $html = $this->renderCommercialPlanForm('en', 'Implementation Standard', CommercialPhase::IMPLEMENTATION, 4900);

        self::assertStringNotContainsString('backend.commercial_plans.form.', $html);
        self::assertStringContainsString('Edit commercial plan', $html);
        self::assertStringContainsString('Single commercial-tier configuration', $html);
        self::assertStringContainsString('Name', $html);
        self::assertStringContainsString('Evidence limit', $html);
        self::assertStringContainsString('Implementation', $html);
        self::assertStringContainsString('Elaboration', $html);
        self::assertStringContainsString('Coming soon', $html);
        self::assertStringNotContainsString('Stripe Price ID', $html);
        self::assertStringContainsString('249 € / 499 € / 749 €', $html);
    }

    public function testIndexGroupsSixInternalPlansIntoThreeCommercialTiers(): void
    {
        $container = self::getContainer();
        $user = (new User())
            ->setName('Super')
            ->setSurnames('Admin')
            ->setEmail('super.admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_SUPER_ADMIN'])
            ->setIsVerified(true);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $request = Request::create('/admin/commercial-plans');
        $request->setLocale('es');
        $request->attributes->set('_route', 'admin_commercial_plans_index');
        $request->attributes->set('_route_params', []);
        $container->get('request_stack')->push($request);
        $container->get('twig')->addGlobal('userProjects', []);

        $plans = [];
        $id = 11;
        foreach (['basic', 'standard', 'pro'] as $code) {
            foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
                $plan = $this->makePlan($phase, $code, 0);
                $this->setEntityId($plan, $id++);
                $plans[] = $plan;
            }
        }

        $repository = $this->createMock(\App\Repository\CommercialPlanRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with([], ['phase' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC'])
            ->willReturn($plans);

        $controller = new CommercialPlanController();
        $controller->setContainer($container);

        $response = $controller->index($repository, new ProjectCommercialPricing());
        $content = (string) $response->getContent();

        self::assertStringNotContainsString('<th>Fase</th>', $content);
        self::assertSame(3, substr_count($content, '<tr>') - 1);
        self::assertStringContainsString('249 € / 499 € / 749 €', $content);
        self::assertStringContainsString('499 € / 999 € / 1.499 €', $content);
        self::assertStringContainsString('/admin/commercial-plans/11/edit', $content);
        self::assertStringContainsString('/admin/commercial-plans/13/edit', $content);
        self::assertStringContainsString('/admin/commercial-plans/15/edit', $content);
    }

    public function testCommercialPlanCannotBePersistedWithoutPhase(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $plan = (new CommercialPlan())
            ->setCode('test-plan')
            ->setName('Test plan')
            ->setDescription('Temporary plan for validation.')
            ->setPriceAmount(0)
            ->setPriceCurrency('EUR')
            ->setMaxEvidenceCount(0)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(99)
            ->setFeatures([]);

        $this->expectException(\LogicException::class);
        $entityManager->persist($plan);
        $entityManager->flush();
    }

    private function renderCommercialPlanForm(string $locale, string $planName, CommercialPhase $phase, int $priceAmount): string
    {
        $container = self::getContainer();
        $translator = $container->get('translator');
        $translator->setLocale($locale);

        $request = Request::create('/admin/commercial-plans/1/edit');
        $request->setLocale($locale);
        $request->attributes->set('_route', 'admin_commercial_plans_edit');
        $request->attributes->set('_route_params', ['id' => 1]);
        $container->get('request_stack')->push($request);

        $user = (new User())
            ->setName('Super')
            ->setSurnames('Admin')
            ->setEmail('super.admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_SUPER_ADMIN'])
            ->setIsVerified(true);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $container->get('twig')->addGlobal('userProjects', []);

        $code = $phase === CommercialPhase::ELABORATION ? 'basic' : 'standard';
        $elaboration = $this->makePlan(CommercialPhase::ELABORATION, $code, $priceAmount);
        $implementation = $this->makePlan(CommercialPhase::IMPLEMENTATION, $code, $priceAmount);
        $elaboration->setName($planName);
        $implementation->setName($planName);
        $form = $container->get('form.factory')->create(CommercialTierType::class, null, [
            'csrf_protection' => false,
            'elaboration_plan' => $elaboration,
            'implementation_plan' => $implementation,
        ]);

        return $container->get('twig')->render('admin/commercial_plan/form.html.twig', [
            'tier' => $code,
            'form' => $form->createView(),
            'priceBands' => (new ProjectCommercialPricing())->getTierPricesCents($code),
            'statusMismatch' => false,
        ]);
    }

    private function makePlan(CommercialPhase $phase, string $code, int $priceAmount): CommercialPlan
    {
        return (new CommercialPlan())
            ->setPhase($phase)
            ->setCode($code)
            ->setName(ucfirst($code))
            ->setDescription('Description')
            ->setPriceAmount($priceAmount)
            ->setPriceCurrency('EUR')
            ->setMaxEvidenceCount(10)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(['basic' => 1, 'standard' => 2, 'pro' => 3][$code])
            ->setFeatures(['allowed_scores' => [4, 5]]);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        if (!$reflection->hasProperty('id')) {
            return;
        }

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
