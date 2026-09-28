<?php

namespace App\Tests\Service\Animation;

use App\Entity\AnimationMeasureMetadata;
use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Service\Animation\AnimationMeasure;
use App\Service\MeasureTaxonomyPresenter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class AnimationEditorialPresentationTest extends KernelTestCase
{
    public function testEditorialPartialPresentsPersistedSpanishAndEnglishTextWithoutInternalCodes(): void
    {
        self::bootKernel();
        $measure = $this->measure();
        $twig = self::getContainer()->get(Environment::class);

        $spanish = $twig->render('backend/plan/_animation_measure_editorial.html.twig', ['measure' => $measure]);
        self::assertStringContainsString('Descripción editorial ES', $spanish);
        self::assertStringContainsString('Implementación editorial ES', $spanish);
        self::assertStringContainsString('Condición humana visible', $spanish);
        self::assertStringContainsString('Impacto esperado', $spanish);
        self::assertStringNotContainsString('TEC_', $spanish);
        self::assertStringNotContainsString('PLAN_', $spanish);

        $measure
            ->setName('Editorial measure EN')
            ->setDescription('Editorial description EN')
            ->setImplementation('Editorial implementation EN');
        self::getContainer()->get('translator')->setLocale('en');
        $english = $twig->render('backend/plan/_animation_measure_editorial.html.twig', ['measure' => $measure]);
        self::assertStringContainsString('Editorial description EN', $english);
        self::assertStringContainsString('Editorial implementation EN', $english);
        self::assertStringContainsString('Expected impact', $english);

        $card = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [],
            'taxonomyPresenter' => new MeasureTaxonomyPresenter(),
            'projectType' => 'rodaje',
            'isAnimation' => true,
        ]);
        self::assertStringContainsString('Editorial measure EN', $card);
        self::assertStringNotContainsString('Pregunta técnica DEV', $card);
        self::assertStringNotContainsString('ANI-EDITORIAL', $card);
        self::assertStringContainsString('data-value="true"', $card);
        self::assertStringContainsString('data-value="false"', $card);
        self::assertStringContainsString('data-value="na"', $card);

        $notApplicableForbidden = $this->measure(false);
        $forbiddenCard = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $notApplicableForbidden,
            'planMeasures' => [],
            'taxonomyPresenter' => new MeasureTaxonomyPresenter(),
            'projectType' => 'rodaje',
            'isAnimation' => true,
        ]);
        self::assertStringContainsString('data-value="true"', $forbiddenCard);
        self::assertStringContainsString('data-value="false"', $forbiddenCard);
        self::assertStringNotContainsString('data-value="na"', $forbiddenCard);
    }

    public function testScoreBadgeIsHiddenForAnimationAndPreservedForFilm(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $translator = self::getContainer()->get('translator');
        $translator->setLocale('es');

        $measure = $this->measure()->setScore(7);
        $planMeasure = (new PlanMeasure())->setMeasure($measure);
        $context = [
            'implementationItems' => [[
                'measure' => $measure,
                'planMeasure' => $planMeasure,
                'operationalState' => 'pending',
            ]],
            'openId' => null,
            'project' => null,
            'taxonomyPresenter' => new MeasureTaxonomyPresenter(),
            'verificationSources' => [],
        ];
        $scoreBadgeTitle = $translator->trans('backend.plan.review.score_badge_title');
        $scoreText = '7 '.$translator->trans('backend.common.points');

        $animation = $twig->render('backend/plan/_list.html.twig', $context + ['isAnimation' => true]);
        self::assertStringNotContainsString($scoreBadgeTitle, $animation);
        self::assertStringNotContainsString($scoreText, $animation);

        $film = $twig->render('backend/plan/_list.html.twig', $context + ['isAnimation' => false]);
        self::assertStringContainsString($scoreBadgeTitle, $film);
        self::assertStringContainsString($scoreText, $film);
    }

    private function measure(bool $notApplicableAllowed = true): Measure
    {
        $filter = new AnimationMeasure(
            id: 'ANI-EDITORIAL', active: true, visualOrder: 1,
            planCompatibility: [], forceIfTechniqueMatches: false, forceIfShooting: false,
            notApplicableAllowed: $notApplicableAllowed, operationalCondition: 'Condición humana visible',
            techniqueCompatibility: [], structureCompatibility: [], processingLevelCompatibility: [],
            processingInfrastructureCompatibility: [], shootingCompatibility: [], aiCompatibility: [],
            interactiveCompatibility: [],
        );
        $measure = (new Measure())
            ->setCatalogId('ANI-EDITORIAL')
            ->setName('Medida editorial ES')
            ->setQuestionText('Pregunta técnica DEV')
            ->setDescription('Descripción editorial ES')
            ->setImplementation('Implementación editorial ES');
        (new \ReflectionProperty(Measure::class, 'id'))->setValue($measure, 42);
        (new AnimationMeasureMetadata())->setMeasure($measure)->syncFrom($filter, 'Alto', 'Medio', 'Baja');

        return $measure;
    }
}
