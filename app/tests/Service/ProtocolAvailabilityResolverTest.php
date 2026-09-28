<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\ProjectCatalog;
use App\Repository\ProtocolRepository;
use App\Service\Animation\AnimationCatalogImporter;
use App\Service\ProtocolAvailabilityResolver;
use PHPUnit\Framework\TestCase;

final class ProtocolAvailabilityResolverTest extends TestCase
{
    public function testAvailabilityUsesProjectTypeAndAnimationGenreForChoicesAndServerValidation(): void
    {
        $film = (new Protocol())->setCode('be-green-my-film')->setName('Film')->setType(Protocol::TYPE_RODAJE);
        $event = (new Protocol())->setCode('be-green-my-event')->setName('Event')->setType(Protocol::TYPE_EVENTO);
        $animation = (new Protocol())->setCode(AnimationCatalogImporter::PROTOCOL_CODE)->setName('Animation')->setType(Protocol::TYPE_RODAJE);

        $repository = $this->createMock(ProtocolRepository::class);
        $repository->method('findForProjectType')->willReturnCallback(
            static fn (string $type): array => Protocol::TYPE_EVENTO === $type
                ? [$event]
                : [$film, $animation],
        );
        $resolver = new ProtocolAvailabilityResolver($repository);

        $animationProject = (new Project())->setType(Protocol::TYPE_RODAJE)->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $filmProject = (new Project())->setType(Protocol::TYPE_RODAJE)->setFilmingGenre('ficcion');
        $eventProject = (new Project())->setType(Protocol::TYPE_EVENTO);

        self::assertSame([$animation], $resolver->getAvailableProtocols($animationProject));
        self::assertSame([$film], $resolver->getAvailableProtocols($filmProject));
        self::assertSame([$event], $resolver->getAvailableProtocols($eventProject));
        self::assertFalse($resolver->isAvailable($filmProject, $animation));
        self::assertFalse($resolver->isAvailable($eventProject, $animation));
        self::assertFalse($resolver->isAvailable($animationProject, $film));
    }

    public function testMissingAnimationProtocolDoesNotFallBackToFilm(): void
    {
        $film = (new Protocol())->setCode('be-green-my-film')->setName('Film')->setType(Protocol::TYPE_RODAJE);
        $repository = $this->createMock(ProtocolRepository::class);
        $repository->method('findForProjectType')->willReturn([$film]);
        $project = (new Project())
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        self::assertSame([], (new ProtocolAvailabilityResolver($repository))->getAvailableProtocols($project));
    }
}
