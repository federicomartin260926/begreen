<?php

namespace App\DataFixtures;

use App\Entity\CommercialPlan;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class CommercialPlanFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['commercial_plans', 'demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $definitions = [
            [
                'code' => 'basic',
                'name' => 'Basic',
                'description' => 'Plan gratuito de entrada.',
                'priceAmount' => 0,
                'priceCurrency' => 'EUR',
                'maxEvidenceCount' => 10,
                'watermarkEnabled' => true,
                'active' => true,
                'sortOrder' => 1,
                'features' => [
                    'allowed_scores' => [4, 5],
                    'sustainability_plan.unified_pdf' => true,
                    'sustainability_plan.evidence_upload' => true,
                    'sustainability_plan.watermark_free_pdf' => false,
                    'sustainability_plan.department_pdf' => false,
                    'sustainability_plan.export.department_pdf' => false,
                    'sustainability_plan.history' => false,
                    'sustainability_plan.advanced_exports' => false,
                    'sustainability_plan.export.category' => false,
                    'sustainability_plan.export.department' => false,
                    'sustainability_plan.export.impact_area' => false,
                    'sustainability_plan.export.triple_balance' => false,
                    'sustainability_plan.export.ods' => false,
                    'sustainability_plan.export.excel' => false,
                    'sustainability_plan.custom_comments' => false,
                    'sustainability_plan.public_comments' => false,
                    'sustainability_plan.internal_notes' => false,
                    'sustainability_plan.responsibles' => false,
                    'sustainability_plan.checklist' => false,
                    'sustainability_plan.custom_measures' => false,
                    'sustainability_plan.validation_summary' => false,
                    'sustainability_plan.branding' => false,
                ],
            ],
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Plan intermedio.',
                'priceAmount' => 9900,
                'priceCurrency' => 'EUR',
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 2,
                'features' => [
                    'allowed_scores' => [3, 4, 5],
                    'sustainability_plan.unified_pdf' => true,
                    'sustainability_plan.evidence_upload' => true,
                    'sustainability_plan.watermark_free_pdf' => true,
                    'sustainability_plan.department_pdf' => true,
                    'sustainability_plan.export.department_pdf' => true,
                    'sustainability_plan.history' => true,
                    'sustainability_plan.advanced_exports' => false,
                    'sustainability_plan.export.category' => false,
                    'sustainability_plan.export.department' => false,
                    'sustainability_plan.export.impact_area' => false,
                    'sustainability_plan.export.triple_balance' => false,
                    'sustainability_plan.export.ods' => false,
                    'sustainability_plan.export.excel' => false,
                    'sustainability_plan.custom_comments' => false,
                    'sustainability_plan.public_comments' => false,
                    'sustainability_plan.internal_notes' => false,
                    'sustainability_plan.responsibles' => false,
                    'sustainability_plan.checklist' => false,
                    'sustainability_plan.custom_measures' => false,
                    'sustainability_plan.validation_summary' => false,
                    'sustainability_plan.branding' => false,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Plan completo.',
                'priceAmount' => 19900,
                'priceCurrency' => 'EUR',
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 3,
                'features' => [
                    'allowed_scores' => [1, 2, 3, 4, 5],
                    'sustainability_plan.unified_pdf' => true,
                    'sustainability_plan.evidence_upload' => true,
                    'sustainability_plan.watermark_free_pdf' => true,
                    'sustainability_plan.department_pdf' => true,
                    'sustainability_plan.export.department_pdf' => true,
                    'sustainability_plan.history' => true,
                    'sustainability_plan.advanced_exports' => true,
                    'sustainability_plan.export.category' => true,
                    'sustainability_plan.export.department' => true,
                    'sustainability_plan.export.impact_area' => true,
                    'sustainability_plan.export.triple_balance' => true,
                    'sustainability_plan.export.ods' => true,
                    'sustainability_plan.export.excel' => true,
                    'sustainability_plan.custom_comments' => true,
                    'sustainability_plan.public_comments' => true,
                    'sustainability_plan.internal_notes' => true,
                    'sustainability_plan.responsibles' => true,
                    'sustainability_plan.checklist' => true,
                    'sustainability_plan.custom_measures' => true,
                    'sustainability_plan.validation_summary' => true,
                    'sustainability_plan.branding' => true,
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $plan = $manager->getRepository(CommercialPlan::class)->findOneBy(['code' => $definition['code']]);
            if (!$plan instanceof CommercialPlan) {
                $plan = new CommercialPlan();
                $manager->persist($plan);
            }

            $plan
                ->setCode($definition['code'])
                ->setName($definition['name'])
                ->setDescription($definition['description'])
                ->setPriceAmount($definition['priceAmount'])
                ->setPriceCurrency($definition['priceCurrency'])
                ->setMaxEvidenceCount($definition['maxEvidenceCount'])
                ->setWatermarkEnabled($definition['watermarkEnabled'])
                ->setActive($definition['active'])
                ->setSortOrder($definition['sortOrder'])
                ->setFeatures($definition['features']);
        }

        $manager->flush();
    }
}
