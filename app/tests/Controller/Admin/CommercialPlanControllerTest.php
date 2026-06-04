<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CommercialPlanController;
use App\Entity\CommercialPlan;
use App\Form\CommercialPlanType;
use Doctrine\ORM\EntityManagerInterface;
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
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $plan = (new CommercialPlan())
            ->setCode('standard-test')
            ->setName('Standard')
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
        $em->persist($plan);
        $em->flush();

        $form = $container->get('form.factory')->create(CommercialPlanType::class, $plan, [
            'csrf_protection' => false,
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
}
