<?php

namespace App\Service\Emission;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WoodCatalog
{
    public const VERSION = 'v1';

    /** @var array<string, float>|null */
    private ?array $defaultDensities = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/emissions/wood')]
        private readonly string $catalogDirectory,
    ) {
    }

    /**
     * @return array<string, float>
     */
    public function getDefaultDensities(): array
    {
        if ($this->defaultDensities !== null) {
            return $this->defaultDensities;
        }

        $path = sprintf('%s/%s/default_densities.json', $this->catalogDirectory, self::VERSION);
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (($data['catalogVersion'] ?? null) !== self::VERSION || !is_array($data['densitiesKgM3'] ?? null)) {
            throw new \RuntimeException('Invalid wood density catalog.');
        }

        foreach (['unknown', 'solid', 'non_solid'] as $classification) {
            $density = $data['densitiesKgM3'][$classification] ?? null;
            if (!is_numeric($density) || (float) $density <= 0) {
                throw new \RuntimeException('Invalid wood density catalog.');
            }
        }

        return $this->defaultDensities = array_map(
            static fn (mixed $density): float => (float) $density,
            $data['densitiesKgM3'],
        );
    }

    public function getDefaultDensity(string $classification): float
    {
        return $this->getDefaultDensities()[$classification]
            ?? throw new \InvalidArgumentException('invalid_classification');
    }
}
