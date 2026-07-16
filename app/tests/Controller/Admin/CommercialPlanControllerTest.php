<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CommercialPlanController;
use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use App\Form\CommercialPlanType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
            ->setName('Pro')
            ->setDescription('Plan intermedio.')
            ->setPriceAmount(9900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_standard_old')
            ->setStripeUpgradeFromStandardPriceId('price_upgrade_old')
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
            'priceAmount' => 1234,
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
            ->setName('Pro')
            ->setDescription('Plan avanzado.')
            ->setPriceAmount(19900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_pro_live')
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
            ->setName('Basic')
            ->setDescription('Plan gratuito.')
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
            ->setName('Standard')
            ->setDescription('Plan estándar.')
            ->setPriceAmount(9900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_standard')
            ->setStripeUpgradeFromStandardPriceId(null)
            ->setMaxEvidenceCount(null)
            ->setWatermarkEnabled(false)
            ->setActive(true)
            ->setSortOrder(2)
            ->setFeatures([]);

        $proPlan = (new CommercialPlan())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setCode('pro')
            ->setName('Pro')
            ->setDescription('Plan Pro.')
            ->setPriceAmount(19900)
            ->setPriceCurrency('EUR')
            ->setStripePriceId('price_pro')
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
}
