<?php

namespace App\Service\Emission;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WoodCatalog
{
    public const VERSION = 'v1';

    /** @var array<string, float>|null */
    private ?array $defaultDensities = null;

    private ?array $scenarios = null;

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

    public function getScenarioCatalog(): array
    {
        if ($this->scenarios !== null) {
            return $this->scenarios;
        }

        $path = sprintf('%s/%s/scenarios_3_4.json', $this->catalogDirectory, self::VERSION);
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null) !== 1 || !is_array($data['solid_woods'] ?? null) || !is_array($data['boards'] ?? null)) {
            throw new \RuntimeException('Invalid wood scenarios catalog.');
        }

        $solidWoods = [];
        foreach ($data['solid_woods'] as $wood) {
            if (!is_array($wood) || !is_string($wood['key'] ?? null) || !is_string($wood['label_es'] ?? null)) {
                throw new \RuntimeException('Invalid wood scenarios catalog.');
            }
            $solidWoods[$wood['key']] = [
                'label' => $wood['label_es'],
                'densityKgM3' => $this->positiveCatalogNumber($wood['density_kg_m3'] ?? null),
            ];
        }

        $boards = [];
        foreach ($data['boards'] as $key => $board) {
            if (!is_string($key) || !is_array($board) || !is_string($board['label_es'] ?? null) || !is_bool($board['fixed'] ?? null) || !is_array($board['options'] ?? null)) {
                throw new \RuntimeException('Invalid wood scenarios catalog.');
            }
            $options = [];
            foreach ($board['options'] as $option) {
                $options[] = $this->normalizeBoardOption($option, false);
            }
            $boards[$key] = [
                'label' => $board['label_es'],
                'fixed' => $board['fixed'],
                'options' => $options,
                'unknown' => isset($board['unknown']) ? $this->normalizeBoardOption($board['unknown'], true) : null,
            ];
        }

        return $this->scenarios = ['solidWoods' => $solidWoods, 'boards' => $boards];
    }

    public function getSolidWood(string $key): array
    {
        return $this->getScenarioCatalog()['solidWoods'][$key]
            ?? throw new \InvalidArgumentException('invalid_species');
    }

    public function getBoardOption(string $family, string $optionKey): array
    {
        $board = $this->getScenarioCatalog()['boards'][$family] ?? null;
        if ($board === null) {
            throw new \InvalidArgumentException('invalid_board');
        }
        if ($optionKey === 'unknown') {
            return $board['unknown'] ?? throw new \InvalidArgumentException('invalid_board_option');
        }
        if (!ctype_digit($optionKey) || !isset($board['options'][(int) $optionKey])) {
            throw new \InvalidArgumentException('invalid_board_option');
        }

        return $board['options'][(int) $optionKey];
    }

    private function normalizeBoardOption(mixed $option, bool $allowUnknownThickness): array
    {
        if (!is_array($option)) {
            throw new \RuntimeException('Invalid wood scenarios catalog.');
        }
        $thickness = $option['thickness_mm'] ?? null;
        if ((!$allowUnknownThickness || $thickness !== null) && (!is_numeric($thickness) || (float) $thickness <= 0)) {
            throw new \RuntimeException('Invalid wood scenarios catalog.');
        }

        return [
            'thicknessMm' => $thickness === null ? null : (float) $thickness,
            'lengthM' => $this->positiveCatalogNumber($option['length_m'] ?? null),
            'widthM' => $this->positiveCatalogNumber($option['width_m'] ?? null),
            'densityKgM3' => $this->positiveCatalogNumber($option['density_kg_m3'] ?? null),
        ];
    }

    private function positiveCatalogNumber(mixed $value): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \RuntimeException('Invalid wood scenarios catalog.');
        }

        return (float) $value;
    }
}
