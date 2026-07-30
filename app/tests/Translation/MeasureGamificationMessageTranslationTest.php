<?php

namespace App\Tests\Translation;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Service\SustainabilityGamificationService;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MeasureGamificationMessageTranslationTest extends KernelTestCase
{
    public function testPendingMeasureMessageUsesEnglishTranslationLoadedByGedmo(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        /** @var TranslatableListener $listener */
        $listener = self::getContainer()->get(TranslatableListener::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $listener->setTranslatableLocale('es');
            $protocol = (new Protocol())
                ->setCode('gamification-translation-' . bin2hex(random_bytes(6)))
                ->setName('Protocolo de traducción')
                ->setType(Protocol::TYPE_RODAJE);
            $measure = (new Measure())
                ->setProtocol($protocol)
                ->setName('Medida traducible')
                ->setGamificationMessage('Mensaje inspiracional ES')
                ->setScore(1);

            $entityManager->persist($protocol);
            $entityManager->persist($measure);

            /** @var TranslationRepository $translationRepository */
            $translationRepository = $entityManager->getRepository(Translation::class);
            $translationRepository->translate(
                $measure,
                'gamificationMessage',
                'en',
                'Inspirational message EN'
            );
            $entityManager->flush();
            $measureId = $measure->getId();
            self::assertNotNull($measureId);

            $entityManager->clear();
            $listener->setTranslatableLocale('en');
            $translatedMeasure = $entityManager->find(Measure::class, $measureId);
            self::assertInstanceOf(Measure::class, $translatedMeasure);
            self::assertSame('Inspirational message EN', $translatedMeasure->getGamificationMessage());

            $plan = new Plan();
            $planMeasure = (new PlanMeasure())->setMeasure($translatedMeasure);
            $plan
                ->addPlanMeasure($planMeasure)
                ->queueGamificationMessage('measure.' . $measureId, 'measure', $measureId);

            /** @var SustainabilityGamificationService $gamificationService */
            $gamificationService = self::getContainer()->get(SustainabilityGamificationService::class);
            $message = $gamificationService->claimPendingMessageForDisplay(
                $plan,
                new Project(),
                $measureId + 1
            );

            self::assertSame('Inspirational message EN', $message['text']);
            self::assertFalse($plan->hasPendingGamificationMessage());
        } finally {
            $listener->setTranslatableLocale('es');
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
