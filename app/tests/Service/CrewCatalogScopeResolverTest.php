<?php

namespace App\Tests\Service;

use App\Entity\CrewDepartment;
use App\Entity\Project;
use App\Enum\ProjectCatalog;
use App\Service\CrewCatalogScopeResolver;
use PHPUnit\Framework\TestCase;

final class CrewCatalogScopeResolverTest extends TestCase
{
    private CrewCatalogScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CrewCatalogScopeResolver();
    }

    public function testResolvesFilmingScope(): void
    {
        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre('ficcion');

        self::assertSame(CrewDepartment::SCOPE_FILMING, $this->resolver->resolve($project));
    }

    public function testResolvesEventScope(): void
    {
        $project = (new Project())
            ->setType('evento')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        self::assertSame(CrewDepartment::SCOPE_EVENT, $this->resolver->resolve($project));
    }

    public function testResolvesAnimationScope(): void
    {
        $project = (new Project())
            ->setType('rodaje')
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);

        self::assertSame(CrewDepartment::SCOPE_ANIMATION, $this->resolver->resolve($project));
    }
}
