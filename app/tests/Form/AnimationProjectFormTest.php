<?php

namespace App\Tests\Form;

use App\Entity\Project;
use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Form\ProjectType;
use App\Repository\CommercialPlanRepository;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\Animation\AnimationConfigurationCatalog;
use App\Service\CommercialPlanResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;

final class AnimationProjectFormTest extends KernelTestCase
{
    public function testNewFormHasNoBusinessDefaultsAndCatalogMetadataIsExact(): void
    {
        $form = $this->createProjectForm(new Project());
        $view = $form->createView();
        $animation = $view->children['animationConfiguration'];
        $techniques = $animation->children['techniques']->children;

        self::assertNull($view->children['filmingGenre']->vars['data']);
        self::assertCount(4, $view->children['filmingGenre']->vars['choices']);
        self::assertCount(21, $techniques);
        self::assertSame(
            array_values(AnimationConfigurationCatalog::TECHNIQUES),
            array_map(static fn ($choice): string => $choice->vars['value'], $techniques),
        );

        $forced = array_values(array_map(
            static fn ($choice): string => $choice->vars['value'],
            array_filter($techniques, static fn ($choice): bool => '1' === ($choice->vars['attr']['data-force-shooting'] ?? '0')),
        ));
        self::assertCount(13, $forced);
        self::assertSame(AnimationConfigurationCatalog::SHOOTING_FORCED_TECHNIQUES, $forced);

        foreach ($animation->children as $field) {
            foreach ($field->children as $choice) {
                self::assertFalse((bool) ($choice->vars['checked'] ?? false));
            }
        }

        $distributionValues = array_map(
            static fn ($choice): string => $choice->vars['value'],
            $view->children['distributionMedia']->children,
        );
        self::assertContains(ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM, $distributionValues);
    }

    public function testAnimationWithoutTechniquesIsInvalid(): void
    {
        $form = $this->submitAnimation(array_replace($this->completeAnimationData(), ['techniques' => []]));

        self::assertFalse($form->isValid());
    }

    public function testNormalTechniqueWithoutShootingAnswerIsInvalid(): void
    {
        $form = $this->submitAnimation(array_replace($this->completeAnimationData(), [
            'shootingAnswered' => null,
        ]));

        self::assertFalse($form->isValid());
    }

    public function testNormalTechniqueWithNoShootingAndNoAiIsValid(): void
    {
        $form = $this->submitAnimation($this->completeAnimationData());

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertFalse($form->get('animationConfiguration')->getData()['shootingAnswered']);
        self::assertFalse($form->get('animationConfiguration')->getData()['usesAi']);
    }

    public function testForcedTechniqueDoesNotRequireSubmittedShootingAndRejectsNoAsEffectiveTruth(): void
    {
        $form = $this->submitAnimation(array_replace($this->completeAnimationData(), [
            'techniques' => ['TEC_VIRTUAL_PRODUCTION'],
            'shootingAnswered' => '0',
        ]));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testMultipleInfrastructuresAndInteractiveAreMapped(): void
    {
        $project = $this->animationProject();
        $form = $this->createProjectForm($project);
        $form->submit([
            'distributionMedia' => ['streaming', ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM],
            'animationConfiguration' => array_replace($this->completeAnimationData(), [
                'processingInfrastructures' => ['PROC_INFRA_EQUIPOS', 'PROC_INFRA_CLOUD'],
            ]),
        ], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(
            ['PROC_INFRA_EQUIPOS', 'PROC_INFRA_CLOUD'],
            $form->get('animationConfiguration')->getData()['processingInfrastructures'],
        );
        self::assertSame(['streaming', 'interactive'], $project->getDistributionMedia());
    }

    public function testAnimationGenreAndTvFormatRemainIndependent(): void
    {
        $project = $this->animationProject()
            ->setFilmingType('tv_program')
            ->setEpisodios(4)
            ->setDuracionEpisodio(25);

        $form = $this->createProjectForm($project);
        $form->submit(['animationConfiguration' => $this->completeAnimationData()], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('tv_program', $project->getFilmingType());
        self::assertSame(ProjectCatalog::FILMING_GENRE_ANIMATION, $project->getFilmingGenre());
    }

    public function testExistingLegacyTvGenreCanBeEditedAndPreserved(): void
    {
        $project = (new Project())
            ->setName('Legacy TV')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('tv_program')
            ->setFilmingGenre('informativo')
            ->setEpisodios(8)
            ->setDuracionEpisodio(45)
            ->setDistributionMedia(['tv']);
        $form = $this->createProjectForm($project);

        self::assertCount(5, $form->createView()->children['filmingGenre']->vars['choices']);
        $form->submit([
            'name' => 'Legacy TV editado',
            'filmingGenre' => 'informativo',
        ], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('Legacy TV editado', $project->getName());
        self::assertSame('informativo', $project->getFilmingGenre());
        self::assertSame('tv_program', $project->getFilmingType());
        self::assertSame(8, $project->getEpisodios());
        self::assertSame(45, $project->getDuracionEpisodio());
    }

    public function testNewProjectRejectsAManipulatedLegacyGenre(): void
    {
        $project = (new Project())
            ->setName('New film')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('feature')
            ->setDistributionMedia(['cinema']);
        $form = $this->createProjectForm($project);
        $form->submit(['filmingGenre' => 'informativo'], false);

        self::assertFalse($form->isValid());
        self::assertNull($project->getFilmingGenre());
    }

    public function testFilmAndEventDoNotRequireAnimationConfiguration(): void
    {
        $film = (new Project())
            ->setName('Film')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('feature')
            ->setFilmingGenre('ficcion')
            ->setDistributionMedia(['cinema']);
        $filmForm = $this->createProjectForm($film);
        $filmForm->submit([], false);

        $event = (new Project())
            ->setName('Event')
            ->setCountry('ES')
            ->setType('evento')
            ->setEventTypePrimary('corporativo')
            ->setEventModality('virtual')
            ->setEventOnlineConnections(10);
        $eventForm = $this->createProjectForm($event);
        $eventForm->submit([], false);

        self::assertTrue($filmForm->isValid(), (string) $filmForm->getErrors(true));
        self::assertTrue($eventForm->isValid(), (string) $eventForm->getErrors(true));
        self::assertNull($film->getAnimationConfiguration());
        self::assertNull($event->getAnimationConfiguration());
    }

    /** @param array<string, mixed> $animationData */
    private function submitAnimation(array $animationData): FormInterface
    {
        $form = $this->createProjectForm($this->animationProject());
        $form->submit(['animationConfiguration' => $animationData], false);

        return $form;
    }

    private function createProjectForm(Project $project): FormInterface
    {
        self::bootKernel();

        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $planRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static fn (CommercialPhase $phase, string $code): CommercialPlan => (new CommercialPlan())
                ->setPhase($phase)
                ->setCode($code)
                ->setName(ucfirst($code)),
        );
        $resolver = new CommercialPlanResolver(
            $planRepository,
            $this->createMock(ProjectSubscriptionRepository::class),
        );
        self::getContainer()->set(CommercialPlanResolver::class, $resolver);

        return self::getContainer()->get('form.factory')->create(ProjectType::class, $project, [
            'csrf_protection' => false,
            'show_commercial_tier' => false,
            'show_emission_source' => false,
        ]);
    }

    private function animationProject(): Project
    {
        return (new Project())
            ->setName('Animation')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('feature')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION)
            ->setDistributionMedia(['streaming']);
    }

    /** @return array<string, mixed> */
    private function completeAnimationData(): array
    {
        return [
            'techniques' => ['TEC_2D_DIGITAL'],
            'shootingAnswered' => '0',
            'structure' => 'ESC_MICRO',
            'processingLevel' => 'PROC_NIVEL_BASICO',
            'processingInfrastructures' => ['PROC_INFRA_EQUIPOS'],
            'usesAi' => '0',
        ];
    }
}
