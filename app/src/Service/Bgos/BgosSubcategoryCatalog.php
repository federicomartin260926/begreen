<?php

namespace App\Service\Bgos;

use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Material\MaterialUiCatalog;
use App\Service\Emission\Transport\TransportUiCatalog;
use App\Service\Emission\Waste\WasteUiCatalog;
use App\Service\Emission\Water\WaterEmissionInput;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BgosSubcategoryCatalog
{
    public const CATEGORY_KEYS = [
        'transport',
        'energy',
        'water',
        'accommodation',
        'catering',
        'materials',
        'waste',
    ];

    public function __construct(
        private readonly TransportUiCatalog $transportCatalog,
        private readonly MaterialUiCatalog $materialCatalog,
        private readonly WasteUiCatalog $wasteCatalog,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{
     *     key: string,
     *     labelKey: string,
     *     subcategories: list<array{
     *         categoryKey: string,
     *         subcategoryKey: string,
     *         label: string,
     *         labelKey: ?string,
     *         groupKey: ?string,
     *         groupLabel: ?string,
     *         sourceKeys: list<string>
     *     }>
     * }>
     */
    public function categories(): array
    {
        $definitions = [
            'transport' => $this->transportDefinitions(),
            'energy' => $this->translatedDefinitions('energy', [
                EnergyEmissionInput::FAMILY_ELECTRICITY,
                EnergyEmissionInput::FAMILY_EQUIPMENT,
                EnergyEmissionInput::FAMILY_BATTERY,
                EnergyEmissionInput::FAMILY_DIGITAL,
            ], 'backend.emission.energy_v1.families.'),
            'water' => $this->translatedDefinitions(
                'water',
                WaterEmissionInput::waterUseTypes(),
                'backend.emission.water_v1.water_use_types.',
            ),
            'accommodation' => $this->translatedDefinitions(
                'accommodation',
                AccommodationEmissionInput::accommodationTypes(),
                'backend.emission.accommodation_v1.types.',
            ),
            'catering' => $this->translatedDefinitions(
                'catering',
                CateringEmissionInput::activityTypes(),
                'backend.emission.catering_v1.activities.',
            ),
            'materials' => $this->materialDefinitions(),
            'waste' => $this->wasteDefinitions(),
        ];

        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'labelKey' => 'backend.emission.index.dashboard.categories.'.$key,
                'subcategories' => $definitions[$key],
            ],
            self::CATEGORY_KEYS,
        );
    }

    /** @return array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}|null */
    public function find(string $categoryKey, string $subcategoryKey): ?array
    {
        foreach ($this->categories() as $category) {
            if ($categoryKey !== $category['key']) {
                continue;
            }
            foreach ($category['subcategories'] as $definition) {
                if ($subcategoryKey === $definition['subcategoryKey']) {
                    return $definition;
                }
            }

            return null;
        }

        return null;
    }

    /** @return array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}|null */
    public function findBySourceKey(string $categoryKey, string $sourceKey): ?array
    {
        foreach ($this->categories() as $category) {
            if ($categoryKey !== $category['key']) {
                continue;
            }
            foreach ($category['subcategories'] as $definition) {
                if (in_array($sourceKey, $definition['sourceKeys'], true)) {
                    return $definition;
                }
            }

            return null;
        }

        return null;
    }

    /** @return list<array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}> */
    private function transportDefinitions(): array
    {
        $categories = $this->transportCatalog->categories();
        foreach (['local', 'travel', 'freight'] as $requiredCategory) {
            if (!array_key_exists($requiredCategory, $categories)) {
                throw new \LogicException(sprintf('Missing modern transport category "%s".', $requiredCategory));
            }
        }

        return [
            $this->definition(
                'transport',
                'people',
                'backend.bgos.transport.people',
                ['local', 'travel'],
            ),
            $this->definition(
                'transport',
                'freight',
                'backend.emission.transport_v20.categories.freight',
                ['freight'],
            ),
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @return list<array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}>
     */
    private function translatedDefinitions(string $categoryKey, array $sourceKeys, string $labelPrefix): array
    {
        return array_map(
            fn (string $sourceKey): array => $this->definition(
                $categoryKey,
                $this->technicalKey($sourceKey),
                $labelPrefix.$sourceKey,
                [$sourceKey],
            ),
            $sourceKeys,
        );
    }

    /** @return list<array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}> */
    private function materialDefinitions(): array
    {
        $definitions = [];
        foreach ($this->materialCatalog->frontendCatalog()['families'] as $family) {
            foreach ($family['activities'] as $activity) {
                $definitions[] = $this->rawDefinition(
                    'materials',
                    $activity['value'],
                    $activity['label'],
                    $family['value'],
                    $family['label'],
                );
            }
        }

        return $this->assertUniqueKeys($definitions, 'materials');
    }

    /** @return list<array{categoryKey:string,subcategoryKey:string,label:string,labelKey:?string,groupKey:?string,groupLabel:?string,sourceKeys:list<string>}> */
    private function wasteDefinitions(): array
    {
        $definitionsBySourceKey = [];
        foreach ($this->wasteCatalog->frontendCatalog() as $region) {
            foreach ($region['types'] as $type) {
                $definitionsBySourceKey[$type['value']] ??= $this->rawDefinition(
                    'waste',
                    $type['value'],
                    $type['label'],
                );
            }
        }

        return $this->assertUniqueKeys(array_values($definitionsBySourceKey), 'waste');
    }

    /** @param list<string> $sourceKeys */
    private function definition(
        string $categoryKey,
        string $subcategoryKey,
        string $labelKey,
        array $sourceKeys,
    ): array {
        return [
            'categoryKey' => $categoryKey,
            'subcategoryKey' => $subcategoryKey,
            'label' => $this->translator->trans($labelKey, [], null, 'es'),
            'labelKey' => $labelKey,
            'groupKey' => null,
            'groupLabel' => null,
            'sourceKeys' => $sourceKeys,
        ];
    }

    private function rawDefinition(
        string $categoryKey,
        string $sourceKey,
        string $label,
        ?string $groupKey = null,
        ?string $groupLabel = null,
    ): array {
        return [
            'categoryKey' => $categoryKey,
            'subcategoryKey' => $this->technicalKey($sourceKey),
            'label' => $label,
            'labelKey' => null,
            'groupKey' => $groupKey,
            'groupLabel' => $groupLabel,
            'sourceKeys' => [$sourceKey],
        ];
    }

    private function technicalKey(string $sourceKey): string
    {
        $key = trim(mb_substr($this->slugger->slug($sourceKey)->lower()->toString(), 0, 150), '-');
        if ('' === $key) {
            throw new \LogicException(sprintf('Cannot derive a BGoS key from "%s".', $sourceKey));
        }

        return $key;
    }

    /** @param list<array{subcategoryKey:string,sourceKeys:list<string>}> $definitions */
    private function assertUniqueKeys(array $definitions, string $categoryKey): array
    {
        $keys = [];
        foreach ($definitions as $definition) {
            $key = $definition['subcategoryKey'];
            if (isset($keys[$key]) && $keys[$key] !== $definition['sourceKeys'][0]) {
                throw new \LogicException(sprintf('Duplicate BGoS key "%s" in category "%s".', $key, $categoryKey));
            }
            $keys[$key] = $definition['sourceKeys'][0];
        }

        return $definitions;
    }
}
