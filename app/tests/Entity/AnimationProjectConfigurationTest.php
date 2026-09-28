<?php

namespace App\Tests\Entity;

use App\Entity\AnimationProjectConfiguration;
use App\Entity\Project;
use App\Enum\ProjectCatalog;
use App\Service\Animation\AnimationConfigurationCatalog;
use App\Service\Animation\AnimationConfigurationFactory;
use App\Service\Animation\AnimationProjectConfigurationUpdater;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimationProjectConfigurationTest extends TestCase
{
    public function testNonForcedConfigurationKeepsAnsweredNoAndEffectiveShootingIsFalse(): void
    {
        [$project, $persisted] = self::configuredProject();
        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL'],
            'ESC_MICRO',
            false,
            'PROC_NIVEL_BASICO',
            ['PROC_INFRA_EQUIPOS'],
            false,
        );

        $effective = (new AnimationConfigurationFactory())->fromProject($project, 'basic');

        self::assertFalse($persisted->getShootingAnswered());
        self::assertFalse($effective->effectiveShooting());
        self::assertNull($effective->isInteractive());
    }

    public function testEnteringAForcedTechniqueClearsOldAnswerAndMakesShootingEffective(): void
    {
        [$project, $persisted] = self::configuredProject();
        $persisted->setTechniques(['TEC_2D_DIGITAL'])->setShootingAnswered(false);

        self::updater()->update(
            $persisted,
            ['TEC_MARIONETAS_STOPMOTION'],
            null,
            false,
            null,
            [],
            null,
        );

        self::assertNull($persisted->getShootingAnswered());
        self::assertTrue((new AnimationConfigurationFactory())->fromProject($project, 'basic')->effectiveShooting());
    }

    public function testManipulatedNoCannotOverrideAForcedTechnique(): void
    {
        [$project, $persisted] = self::configuredProject();
        $persisted
            ->setTechniques(['TEC_VIRTUAL_PRODUCTION'])
            ->setShootingAnswered(false);

        self::assertNull($persisted->getShootingAnswered());
        self::assertTrue((new AnimationConfigurationFactory())->fromProject($project, 'basic')->effectiveShooting());
    }

    public function testRemovingTheLastForcedTechniqueLeavesShootingUnanswered(): void
    {
        [$project, $persisted] = self::configuredProject();
        $persisted->setTechniques(['TEC_MARIONETAS_STOPMOTION']);

        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL'],
            null,
            null,
            null,
            [],
            null,
        );

        $effective = (new AnimationConfigurationFactory())->fromProject($project, 'basic');
        self::assertNull($persisted->getShootingAnswered());
        self::assertNull($effective->effectiveShooting());
    }

    public function testRemovingTheLastForcedTechniqueKeepsANewManualNoAnswer(): void
    {
        [$project, $persisted] = self::configuredProject();
        $persisted->setTechniques(['TEC_MARIONETAS_STOPMOTION']);

        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL'],
            null,
            false,
            null,
            [],
            null,
        );

        self::assertFalse($persisted->getShootingAnswered());
        self::assertFalse((new AnimationConfigurationFactory())->fromProject($project, 'basic')->effectiveShooting());
    }

    public function testShootingStaysForcedWhileAnySelectedTechniqueForcesIt(): void
    {
        [$project, $persisted] = self::configuredProject();
        $persisted->setTechniques(['TEC_MARIONETAS_STOPMOTION', 'TEC_VIRTUAL_PRODUCTION']);

        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL', 'TEC_VIRTUAL_PRODUCTION'],
            null,
            false,
            null,
            [],
            null,
        );

        self::assertNull($persisted->getShootingAnswered());
        self::assertTrue((new AnimationConfigurationFactory())->fromProject($project, 'basic')->effectiveShooting());
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('unknownCodeCases')]
    public function testRejectsUnknownPersistedCodes(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::updater()->update(
            new AnimationProjectConfiguration(),
            $input['techniques'],
            $input['structure'],
            null,
            $input['processingLevel'],
            $input['processingInfrastructures'],
            null,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unknownCodeCases(): iterable
    {
        $valid = [
            'techniques' => [],
            'structure' => null,
            'processingLevel' => null,
            'processingInfrastructures' => [],
        ];

        yield 'technique' => [array_replace($valid, ['techniques' => ['TEC_UNKNOWN']])];
        yield 'structure' => [array_replace($valid, ['structure' => 'ESC_UNKNOWN'])];
        yield 'processing level' => [array_replace($valid, ['processingLevel' => 'PROC_NIVEL_UNKNOWN'])];
        yield 'processing infrastructure' => [array_replace($valid, ['processingInfrastructures' => ['PROC_INFRA_UNKNOWN']])];
    }

    public function testJsonListsAreDeduplicatedInCatalogOrder(): void
    {
        $persisted = new AnimationProjectConfiguration();

        self::updater()->update(
            $persisted,
            ['TEC_VFX', 'TEC_2D_DIGITAL', 'TEC_VFX'],
            null,
            null,
            null,
            ['PROC_INFRA_CLOUD', 'PROC_INFRA_EQUIPOS', 'PROC_INFRA_CLOUD'],
            null,
        );

        self::assertSame(['TEC_2D_DIGITAL', 'TEC_VFX'], $persisted->getTechniques());
        self::assertSame(['PROC_INFRA_EQUIPOS', 'PROC_INFRA_CLOUD'], $persisted->getProcessingInfrastructures());
    }

    public function testInteractiveIsDerivedOnlyFromProjectDistribution(): void
    {
        [$project] = self::configuredProject();
        $factory = new AnimationConfigurationFactory();

        $project->setDistributionMedia(['streaming']);
        self::assertFalse($factory->fromProject($project, 'basic')->isInteractive());

        $project->setDistributionMedia(['streaming', ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM]);
        self::assertTrue($factory->fromProject($project, 'basic')->isInteractive());
        self::assertSame(
            ['streaming', ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM],
            $project->getDistributionMedia(),
        );
    }

    public function testFactoryUsesProjectDistributionAndExternalPlanWithoutPersistingEither(): void
    {
        [$project, $persisted] = self::configuredProject();
        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL'],
            'ESC_MICRO',
            false,
            'PROC_NIVEL_BASICO',
            ['PROC_INFRA_EQUIPOS'],
            false,
        );
        $project->setDistributionMedia([ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM]);

        $effective = (new AnimationConfigurationFactory())->fromProject($project, 'standard');

        self::assertSame($persisted->getTechniques(), $effective->techniques());
        self::assertSame('standard', $effective->plan());
        self::assertTrue($effective->isInteractive());
        self::assertFalse(property_exists($persisted, 'distribution'));
        self::assertFalse(property_exists($persisted, 'plan'));
    }

    public function testNonAnimationProjectDoesNotRequireAndDoesNotDeleteAnimationConfiguration(): void
    {
        $plainProject = new Project();
        self::assertNull($plainProject->getAnimationConfiguration());

        [$project, $persisted] = self::configuredProject();
        $project->setType('evento');
        $project->normalizeState();

        self::assertSame($persisted, $project->getAnimationConfiguration());
        self::assertSame($project, $persisted->getProject());
    }

    public function testManipulatedFilmOrEventInputCannotChangeDormantAnimationConfiguration(): void
    {
        [$project, $persisted] = self::configuredProject();
        self::updater()->update(
            $persisted,
            ['TEC_2D_DIGITAL'],
            'ESC_MICRO',
            false,
            'PROC_NIVEL_BASICO',
            ['PROC_INFRA_EQUIPOS'],
            false,
        );

        $project->setFilmingGenre('ficcion');
        self::updater()->updateProject(
            $project,
            ['TEC_VFX'],
            'ESC_MIXTA',
            true,
            'PROC_NIVEL_INTENSIVO',
            ['PROC_INFRA_CLOUD'],
            true,
        );

        self::assertSame(['TEC_2D_DIGITAL'], $persisted->getTechniques());
        self::assertSame('ESC_MICRO', $persisted->getStructure());
        self::assertFalse($persisted->getShootingAnswered());
        self::assertSame(['PROC_INFRA_EQUIPOS'], $persisted->getProcessingInfrastructures());
        self::assertFalse($persisted->getUsesAi());

        $project->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        self::assertSame($persisted, $project->getAnimationConfiguration());
    }

    public function testAnimationProjectUpdateCreatesConfigurationOnlyWhenNeeded(): void
    {
        $film = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre('ficcion');
        self::updater()->updateProject($film, [], null, null, null, [], null);
        self::assertNull($film->getAnimationConfiguration());

        $film->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $created = self::updater()->updateProject(
            $film,
            ['TEC_2D_DIGITAL'],
            'ESC_MICRO',
            false,
            'PROC_NIVEL_BASICO',
            ['PROC_INFRA_EQUIPOS'],
            false,
        );

        self::assertSame($created, $film->getAnimationConfiguration());
        self::assertSame($film, $created?->getProject());
    }

    public function testAnimationConfigurationIsCopiedToAnotherAnimationProject(): void
    {
        [$source, $sourceConfiguration] = self::configuredProject();
        self::updater()->update(
            $sourceConfiguration,
            ['TEC_2D_DIGITAL', 'TEC_VFX'],
            'ESC_MIXTA',
            false,
            'PROC_NIVEL_INTENSIVO',
            ['PROC_INFRA_EQUIPOS', 'PROC_INFRA_CLOUD'],
            false,
        );
        $target = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        self::assertTrue(self::updater()->copyProjectConfiguration($source, $target));
        $copied = $target->getAnimationConfiguration();
        self::assertNotNull($copied);
        self::assertNotSame($sourceConfiguration, $copied);
        self::assertSame($sourceConfiguration->getTechniques(), $copied->getTechniques());
        self::assertSame($sourceConfiguration->getStructure(), $copied->getStructure());
        self::assertSame($sourceConfiguration->getShootingAnswered(), $copied->getShootingAnswered());
        self::assertSame($sourceConfiguration->getProcessingLevel(), $copied->getProcessingLevel());
        self::assertSame($sourceConfiguration->getProcessingInfrastructures(), $copied->getProcessingInfrastructures());
        self::assertSame($sourceConfiguration->getUsesAi(), $copied->getUsesAi());
    }

    public function testAnimationCloneWithoutPersistedConfigurationIsRejectedWithoutDefaults(): void
    {
        $source = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $target = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        self::assertFalse(self::updater()->copyProjectConfiguration($source, $target));
        self::assertNull($target->getAnimationConfiguration());
    }

    public function testInteractiveIsPersistibleAndExposedByTheAudiovisualForm(): void
    {
        self::assertTrue(ProjectCatalog::isDistributionMedia(ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM));
        self::assertContains(
            ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM,
            array_values(ProjectCatalog::distributionMediaChoices()),
        );
    }

    public function testUnlinkingAnimationConfigurationKeepsBothSidesConsistent(): void
    {
        [$project, $configuration] = self::configuredProject();

        $project->setAnimationConfiguration(null);

        self::assertNull($project->getAnimationConfiguration());
        self::assertNull($configuration->getProject());
    }

    public function testReplacingAnimationConfigurationKeepsBothSidesConsistent(): void
    {
        [$project, $first] = self::configuredProject();
        $second = new AnimationProjectConfiguration();

        $project->setAnimationConfiguration($second);

        self::assertNull($first->getProject());
        self::assertSame($second, $project->getAnimationConfiguration());
        self::assertSame($project, $second->getProject());
    }

    /** @return array{Project, AnimationProjectConfiguration} */
    private static function configuredProject(): array
    {
        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $configuration = (new AnimationProjectConfiguration())->setProject($project);

        return [$project, $configuration];
    }

    private static function updater(): AnimationProjectConfigurationUpdater
    {
        return new AnimationProjectConfigurationUpdater();
    }
}
