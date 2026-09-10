<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Service\Emission\EmissionCountryCatalog;

final class WasteUiCatalog
{
    public const REGION_SPAIN = 'spain';
    public const REGION_OUTSIDE_SPAIN = 'outside_spain';
    public const ROUTE_FACTOR = 'FACTOR';
    public const ROUTE_UNKNOWN = 'DERIVED_MAX_VALID_TREATMENTS';
    public const ROUTE_NON_WASTE_ZERO = 'NON_WASTE_ROUTE_ZERO';

    private const HEADERS = [
        'region_scope', 'region_label', 'waste_type', 'waste_type_label',
        'waste_activity', 'has_subactivity', 'treatment', 'route_type',
        'source_family', 'temporal_type',
    ];

    /** @var list<array<string, string>> */
    private array $routes;
    private readonly EmissionCountryCatalog $countryCatalog;

    public function __construct(?string $catalogFile = null, ?EmissionCountryCatalog $countryCatalog = null)
    {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
        $this->routes = $this->load($catalogFile ?? __DIR__.'/data/waste_catalog_v1.csv');
    }

    public function regionScopeForCountry(string $iso3): string
    {
        return match ($this->countryCatalog->wasteRegion($iso3)) {
            'España' => self::REGION_SPAIN,
            'Fuera de España' => self::REGION_OUTSIDE_SPAIN,
            default => throw new \UnexpectedValueException('Unsupported waste region in emission country catalog.'),
        };
    }

    public function normalizeCountry(string $iso3): string
    {
        return $this->countryCatalog->normalizeIso3($iso3);
    }

    /** @return list<array{value: string, label: string, hasSubactivity: bool}> */
    public function wasteTypesForCountry(string $iso3): array
    {
        $regionScope = $this->regionScopeForCountry($iso3);
        $types = [];
        foreach ($this->routes as $route) {
            if ($regionScope !== $route['region_scope']) {
                continue;
            }
            $types[$route['waste_type']] = [
                'value' => $route['waste_type'],
                'label' => $route['waste_type_label'],
                'hasSubactivity' => 'true' === $route['has_subactivity'],
            ];
        }

        return array_values($types);
    }

    public function hasWasteType(string $iso3, string $wasteType): bool
    {
        foreach ($this->wasteTypesForCountry($iso3) as $type) {
            if ($wasteType === $type['value']) {
                return true;
            }
        }

        return false;
    }

    public function wasteTypeLabel(string $iso3, string $wasteType): ?string
    {
        foreach ($this->wasteTypesForCountry($iso3) as $type) {
            if ($wasteType === $type['value']) {
                return $type['label'];
            }
        }

        return null;
    }

    public function requiresSubactivity(string $iso3, string $wasteType): bool
    {
        foreach ($this->wasteTypesForCountry($iso3) as $type) {
            if ($wasteType === $type['value']) {
                return $type['hasSubactivity'];
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported waste type "%s" for country "%s".', $wasteType, $iso3));
    }

    /** @return list<string> */
    public function activitiesFor(string $iso3, string $wasteType): array
    {
        $regionScope = $this->regionScopeForCountry($iso3);
        $activities = [];
        foreach ($this->routes as $route) {
            if ($regionScope === $route['region_scope'] && $wasteType === $route['waste_type']) {
                $activities[$route['waste_activity']] = true;
            }
        }

        return array_keys($activities);
    }

    public function canonicalActivity(string $iso3, string $wasteType, ?string $wasteActivity): ?string
    {
        $activities = $this->activitiesFor($iso3, $wasteType);
        if ([] === $activities) {
            return null;
        }

        if (!$this->requiresSubactivity($iso3, $wasteType)) {
            $canonical = $activities[0];
            if (null === $wasteActivity || '' === trim($wasteActivity) || $canonical === $wasteActivity) {
                return $canonical;
            }

            return null;
        }

        if (null === $wasteActivity || '' === trim($wasteActivity)) {
            return null;
        }

        return in_array($wasteActivity, $activities, true) ? $wasteActivity : null;
    }

    /** @return list<array<string, string>> */
    public function routesFor(string $iso3, string $wasteType, string $wasteActivity): array
    {
        $regionScope = $this->regionScopeForCountry($iso3);

        return array_values(array_filter(
            $this->routes,
            static fn (array $route): bool => $regionScope === $route['region_scope']
                && $wasteType === $route['waste_type']
                && $wasteActivity === $route['waste_activity'],
        ));
    }

    /** @return array<string, string>|null */
    public function resolveRoute(string $iso3, string $wasteType, string $wasteActivity, string $treatment): ?array
    {
        foreach ($this->routesFor($iso3, $wasteType, $wasteActivity) as $route) {
            if ($treatment === $route['treatment']) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<array<string, string>> */
    public function factorRoutesFor(string $iso3, string $wasteType, string $wasteActivity): array
    {
        return array_values(array_filter(
            $this->routesFor($iso3, $wasteType, $wasteActivity),
            static fn (array $route): bool => self::ROUTE_FACTOR === $route['route_type'],
        ));
    }

    /** @return array{spain: array{types: list<array{value:string,label:string,hasSubactivity:bool,activities:list<array{value:string,treatments:list<string>}>}>}, outside_spain: array{types: list<array{value:string,label:string,hasSubactivity:bool,activities:list<array{value:string,treatments:list<string>}>}>}} */
    public function frontendCatalog(): array
    {
        $result = [
            self::REGION_SPAIN => ['types' => []],
            self::REGION_OUTSIDE_SPAIN => ['types' => []],
        ];
        foreach ([self::REGION_SPAIN => 'ESP', self::REGION_OUTSIDE_SPAIN => 'FRA'] as $region => $country) {
            foreach ($this->wasteTypesForCountry($country) as $type) {
                $activities = [];
                foreach ($this->activitiesFor($country, $type['value']) as $activity) {
                    $activities[] = [
                        'value' => $activity,
                        'treatments' => array_values(array_map(
                            static fn (array $route): string => $route['treatment'],
                            $this->routesFor($country, $type['value'], $activity),
                        )),
                    ];
                }
                $result[$region]['types'][] = [
                    ...$type,
                    'activities' => $activities,
                ];
            }
        }

        return $result;
    }

    /** @return list<array<string, string>> */
    private function load(string $path): array
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected waste UI catalog CSV headers.');
        }

        $routes = [];
        $identities = [];
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed waste UI catalog CSV row %d.', $file->key() + 1));
            }
            /** @var array<string, string> $route */
            $route = array_combine(self::HEADERS, $values);
            if (!in_array($route['region_scope'], [self::REGION_SPAIN, self::REGION_OUTSIDE_SPAIN], true)) {
                throw new \RuntimeException(sprintf('Invalid waste UI catalog region in row %d.', $file->key() + 1));
            }
            if (!in_array($route['route_type'], [self::ROUTE_FACTOR, self::ROUTE_UNKNOWN, self::ROUTE_NON_WASTE_ZERO], true)) {
                throw new \RuntimeException(sprintf('Invalid waste UI catalog route type in row %d.', $file->key() + 1));
            }
            $identity = implode('|', [$route['region_scope'], $route['waste_type'], $route['waste_activity'], $route['treatment']]);
            if (isset($identities[$identity])) {
                throw new \RuntimeException(sprintf('Duplicate waste UI catalog route in row %d.', $file->key() + 1));
            }
            $identities[$identity] = true;
            $routes[] = $route;
        }

        if (341 !== count($routes)) {
            throw new \RuntimeException(sprintf('Expected 341 waste UI catalog routes, got %d.', count($routes)));
        }

        return $routes;
    }
}
