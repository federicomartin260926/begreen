<?php

namespace App\DataFixtures;

use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
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
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'basic',
                'name' => 'Elaboración Basic',
                'description' => 'Plan gratuito de Elaboración para empezar, con PDF unificado, marca de agua activa y límite de 10 evidencias por proyecto.',
                'priceAmount' => 0,
                'priceCurrency' => 'EUR',
                'stripePriceId' => null,
                'stripeUpgradeFromStandardPriceId' => null,
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
                    'sustainability_plan.export.email' => false,
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
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'standard',
                'name' => 'Elaboración Standard',
                'description' => 'Plan de Elaboración con PDF agrupado por departamentos, marca de agua desactivada y evidencias ilimitadas para gestionar proyectos con más detalle.',
                'priceAmount' => 2900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => 'price_1TtuAORudvqjmqq94aHX3Om4',
                'stripeUpgradeFromStandardPriceId' => null,
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
                    'sustainability_plan.export.email' => true,
                    'sustainability_plan.public_comments' => false,
                    'sustainability_plan.internal_notes' => false,
                    'sustainability_plan.responsibles' => false,
                    'sustainability_plan.checklist' => false,
                    'sustainability_plan.custom_measures' => true,
                    'sustainability_plan.validation_summary' => false,
                    'sustainability_plan.branding' => false,
                ],
            ],
            [
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'pro',
                'name' => 'Elaboración Pro',
                'description' => 'Plan de Elaboración con exportaciones avanzadas por categorías, departamentos, áreas de impacto, triple balance y ODS, además de campos colaborativos.',
                'priceAmount' => 4900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => 'price_1TttueRudvqjmqq9BkITaeEb',
                'stripeUpgradeFromStandardPriceId' => 'price_1Ttu7aRudvqjmqq97V6Ilj3L',
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
                    'sustainability_plan.export.email' => true,
                    'sustainability_plan.public_comments' => true,
                    'sustainability_plan.internal_notes' => true,
                    'sustainability_plan.responsibles' => true,
                    'sustainability_plan.checklist' => true,
                    'sustainability_plan.custom_measures' => true,
                    'sustainability_plan.validation_summary' => true,
                    'sustainability_plan.branding' => true,
                ],
            ],
            // Implementacion provisional: matriz minima hasta cerrar el bloque de permisos definitivo.
            [
                'phase' => CommercialPhase::IMPLEMENTATION,
                'code' => 'basic',
                'name' => 'Implementación Basic',
                'description' => 'Plan gratuito de Implementación para empezar, con PDF unificado, marca de agua activa y límite de 10 evidencias por proyecto.',
                'priceAmount' => 0,
                'priceCurrency' => 'EUR',
                'stripePriceId' => null,
                'stripeUpgradeFromStandardPriceId' => null,
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
                    'sustainability_plan.export.email' => false,
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
                'phase' => CommercialPhase::IMPLEMENTATION,
                'code' => 'standard',
                'name' => 'Implementación Standard',
                'description' => 'Plan de Implementación con checklist, responsables, notas internas y evidencias ilimitadas para gestionar la ejecución con más detalle.',
                'priceAmount' => 2900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => 'price_1TttwkRudvqjmqq9KlY7cJdx',
                'stripeUpgradeFromStandardPriceId' => null,
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 2,
                'features' => [
                    'allowed_scores' => [4, 5],
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
                    'sustainability_plan.export.email' => false,
                    'sustainability_plan.public_comments' => false,
                    'sustainability_plan.internal_notes' => true,
                    'sustainability_plan.responsibles' => true,
                    'sustainability_plan.checklist' => true,
                    'sustainability_plan.custom_measures' => false,
                    'sustainability_plan.validation_summary' => false,
                    'sustainability_plan.branding' => false,
                ],
            ],
            [
                'phase' => CommercialPhase::IMPLEMENTATION,
                'code' => 'pro',
                'name' => 'Implementación Pro',
                'description' => 'Plan de Implementación con exportaciones avanzadas por categorías, departamentos, áreas de impacto, triple balance y ODS, además de capacidades avanzadas de ejecución.',
                'priceAmount' => 4900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => 'price_1TttxeRudvqjmqq95jlhpWXG',
                'stripeUpgradeFromStandardPriceId' => 'price_1Ttu8IRudvqjmqq9DxbI41sP',
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 3,
                'features' => [
                    'allowed_scores' => [4, 5],
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
                    'sustainability_plan.export.email' => false,
                    'sustainability_plan.public_comments' => false,
                    'sustainability_plan.internal_notes' => true,
                    'sustainability_plan.responsibles' => true,
                    'sustainability_plan.checklist' => true,
                    'sustainability_plan.custom_measures' => false,
                    'sustainability_plan.validation_summary' => true,
                    'sustainability_plan.branding' => true,
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $plan = $manager->getRepository(CommercialPlan::class)->findOneBy([
                'phase' => $definition['phase'],
                'code' => $definition['code'],
            ]);
            $isNew = false;
            if (!$plan instanceof CommercialPlan) {
                $plan = new CommercialPlan();
                $isNew = true;
            }

            $plan
                ->setPhase($definition['phase'])
                ->setCode($definition['code'])
                ->setName($definition['name'])
                ->setDescription($definition['description'])
                ->setPriceAmount($definition['priceAmount'])
                ->setPriceCurrency($definition['priceCurrency'])
                ->setStripePriceId($definition['stripePriceId'])
                ->setStripeUpgradeFromStandardPriceId($definition['stripeUpgradeFromStandardPriceId'] ?? null)
                ->setMaxEvidenceCount($definition['maxEvidenceCount'])
                ->setWatermarkEnabled($definition['watermarkEnabled'])
                ->setActive($definition['active'])
                ->setSortOrder($definition['sortOrder'])
                ->setFeatures($definition['features']);

            if ($isNew) {
                $manager->persist($plan);
            }
        }

        $manager->flush();
    }
}
