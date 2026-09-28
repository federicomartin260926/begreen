<?php

namespace App\Tests\Service\Animation;

use App\Entity\AnimationMeasureMetadata;
use App\Entity\Measure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\ProjectCatalog;
use App\Service\Animation\AnimationCatalogImporter;
use App\Service\Animation\AnimationMeasure;
use App\Service\Animation\AnimationNotApplicableValidator;
use PHPUnit\Framework\TestCase;

final class AnimationNotApplicableValidatorTest extends TestCase
{
    public function testAnimationMetadataControlsNotApplicableWithoutChangingFilmBehavior(): void
    {
        $project = (new Project())->setType('rodaje')->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $validator = new AnimationNotApplicableValidator();

        self::assertTrue($validator->allows($project, $this->animationMeasure(true)));
        self::assertFalse($validator->allows($project, $this->animationMeasure(false)));

        $filmProject = (new Project())->setType('rodaje')->setFilmingGenre('ficcion');
        $filmMeasure = (new Measure())->setProtocol((new Protocol())->setCode('be-green-my-film'));
        self::assertTrue($validator->allows($filmProject, $filmMeasure));

        $eventProject = (new Project())->setType(Protocol::TYPE_EVENTO);
        $eventMeasure = (new Measure())->setProtocol((new Protocol())->setCode('be-green-my-event'));
        self::assertTrue($validator->allows($eventProject, $eventMeasure));
    }

    private function animationMeasure(bool $notApplicableAllowed): Measure
    {
        $filter = new AnimationMeasure(
            id: 'ANI-TEST', active: true, visualOrder: 1,
            planCompatibility: [], forceIfTechniqueMatches: false, forceIfShooting: false,
            notApplicableAllowed: $notApplicableAllowed, operationalCondition: 'Información solamente.',
            techniqueCompatibility: [], structureCompatibility: [], processingLevelCompatibility: [],
            processingInfrastructureCompatibility: [], shootingCompatibility: [], aiCompatibility: [],
            interactiveCompatibility: [],
        );
        $measure = (new Measure())
            ->setCatalogId('ANI-TEST')
            ->setProtocol((new Protocol())->setCode(AnimationCatalogImporter::PROTOCOL_CODE));
        (new AnimationMeasureMetadata())->setMeasure($measure)->syncFrom($filter, 'Alto', 'Medio', 'Baja');

        return $measure;
    }
}
