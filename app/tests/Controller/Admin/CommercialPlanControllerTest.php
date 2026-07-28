<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CommercialPlanController;
use App\Entity\CommercialPlan;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Form\CommercialPlanType;
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

    public function testEditMapperNormalizesFeaturesAndAliasComments(): void
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
                'sustainability_plan.public_comments' => false,
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
            'publicComments' => true,
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
        self::assertTrue($plan->getFeature('sustainability_plan.public_comments', false));
        self::assertTrue($plan->getFeature('sustainability_plan.custom_comments', false));
        self::assertFalse(array_key_exists('sustainability_plan.custom_comments', $plan->getFeatures()));
    }

    public function testEditFormRendersStripeUpgradePriceRightAfterStripePriceId(): void
    {
        $container = self::getContainer();

        $plan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('pro-test')
            ->setName('Elaboración Pro')
            ->setDescription('Plan avanzado.')
            ->setPriceAmount(4900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_pro_test')
            ->setStripeUpgradeFromStandardPriceId('price_upgrade_live')
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(3)
            ->setFeatures([
                'allowed_scores' => [1, 2, 3, 4, 5],
                'sustainability_plan.department_pdf' => true,
                'sustainability_plan.export.department_pdf' => true,
                'sustainability_plan.watermark_free_pdf' => true,
                'sustainability_plan.history' => true,
                'sustainability_plan.advanced_exports' => true,
                'sustainability_plan.export.category' => true,
                'sustainability_plan.export.department' => true,
                'sustainability_plan.export.impact_area' => true,
                'sustainability_plan.export.triple_balance' => true,
                'sustainability_plan.export.ods' => true,
                'sustainability_plan.export.excel' => true,
                'sustainability_plan.public_comments' => true,
                'sustainability_plan.internal_notes' => true,
                'sustainability_plan.responsibles' => true,
                'sustainability_plan.checklist' => true,
                'sustainability_plan.custom_measures' => true,
                'sustainability_plan.validation_summary' => true,
                'sustainability_plan.branding' => true,
            ]);

        $form = $container->get('form.factory')->create(CommercialPlanType::class, $plan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => true,
        ]);

        self::assertTrue($form->has('stripePriceId'));
        self::assertTrue($form->has('stripeUpgradeFromStandardPriceId'));

        $template = file_get_contents(__DIR__ . '/../../../templates/admin/commercial_plan/form.html.twig');
        self::assertNotFalse($template);
        $templateLines = explode("\n", $template);
        $findLine = static function (array $lines, string $needle): ?int {
            foreach ($lines as $index => $line) {
                if (str_contains($line, $needle)) {
                    return $index + 1;
                }
            }

            return null;
        };

        $stripePriceRowLine = $findLine($templateLines, 'form_row(form.stripePriceId)');
        $stripeUpgradeRowLine = $findLine($templateLines, 'form_row(form.stripeUpgradeFromStandardPriceId)');
        $saveButtonLine = $findLine($templateLines, 'backend.common.save_changes');

        self::assertNotNull($stripePriceRowLine);
        self::assertNotNull($stripeUpgradeRowLine);
        self::assertNotNull($saveButtonLine);
        self::assertTrue($stripePriceRowLine < $stripeUpgradeRowLine);
        self::assertTrue($stripeUpgradeRowLine < $saveButtonLine);
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
        self::assertStringContainsString('Configura el precio, los límites y las funcionalidades disponibles para este plan.', $html);
        self::assertStringContainsString('Nombre', $html);
        self::assertStringContainsString('Moneda', $html);
        self::assertStringContainsString('Límite de evidencias', $html);
        self::assertStringContainsString('Stripe Price ID', $html);
        self::assertStringContainsString('Se configurará al activar la contratación mediante Stripe.', $html);
        self::assertStringContainsString('Elaboración Basic', $html);
        self::assertStringContainsString('Elaboración', $html);
        self::assertStringContainsString('Próximamente', $html);
        self::assertMatchesRegularExpression('/29[,.]00/', $html);
        self::assertStringNotContainsString('2900', $html);
    }

    public function testEditFormRendersTranslatedContentInEnglish(): void
    {
        $html = $this->renderCommercialPlanForm('en', 'Implementation Standard', CommercialPhase::IMPLEMENTATION, 4900);

        self::assertStringNotContainsString('backend.commercial_plans.form.', $html);
        self::assertStringContainsString('Edit commercial plan', $html);
        self::assertStringContainsString('Configure the price, limits and available features for this plan.', $html);
        self::assertStringContainsString('Name', $html);
        self::assertStringContainsString('Currency', $html);
        self::assertStringContainsString('Evidence limit', $html);
        self::assertStringContainsString('Stripe Price ID', $html);
        self::assertStringContainsString('It will be configured when Stripe checkout is activated.', $html);
        self::assertStringContainsString('Implementation Standard', $html);
        self::assertStringContainsString('Implementation', $html);
        self::assertStringContainsString('Coming soon', $html);
        self::assertMatchesRegularExpression('/49[,.]00/', $html);
        self::assertStringNotContainsString('4900', $html);
    }

    public function testIndexDisplaysPhaseColumnAndOrdersByPhaseThenSortOrderThenId(): void
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

        $elaborationBasic = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('basic')
            ->setName('Elaboración Basic')
            ->setDescription('Plan de Elaboración.')
            ->setPriceAmount(0)
            ->setPriceCurrency('EUR')
            ->setMaxEvidenceCount(10)
            ->setWatermarkEnabled(true)
            ->setActive(true)
            ->setSortOrder(1)
            ->setFeatures([]);
        $this->setEntityId($elaborationBasic, 11);

        $implementationPro = (new CommercialPlan())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setCode('pro')
            ->setName('Implementación Pro')
            ->setDescription('Plan de Implementación.')
            ->setPriceAmount(4900)
            ->setPriceCurrency('EUR')
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(3)
            ->setFeatures([]);
        $this->setEntityId($implementationPro, 12);

        $repository = $this->createMock(\App\Repository\CommercialPlanRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with([], ['phase' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC'])
            ->willReturn([$elaborationBasic, $implementationPro]);

        $controller = new CommercialPlanController();
        $controller->setContainer($container);

        $response = $controller->index($repository);
        $content = (string) $response->getContent();

        self::assertStringContainsString('Fase', $content);
        self::assertStringContainsString('Elaboración', $content);
        self::assertStringContainsString('Implementación', $content);
        self::assertTrue(strpos($content, 'Elaboración Basic') < strpos($content, 'Implementación Pro'));
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

        $plan = (new CommercialPlan())
            ->setPhase($phase)
            ->setCode('basic')
            ->setName($planName)
            ->setDescription('Temporary description.')
            ->setPriceAmount($priceAmount)
            ->setPriceCurrency('EUR')
            ->setStripePriceId(null)
            ->setMaxEvidenceCount(10)
            ->setWatermarkEnabled(true)
            ->setActive(true)
            ->setSortOrder(1)
            ->setFeatures([]);

        $form = $container->get('form.factory')->create(CommercialPlanType::class, $plan, [
            'csrf_protection' => false,
            'show_stripe_upgrade_from_standard_price_id' => false,
        ]);

        return $container->get('twig')->render('admin/commercial_plan/form.html.twig', [
            'plan' => $plan,
            'form' => $form->createView(),
        ]);
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
