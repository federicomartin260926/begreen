<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionFactor;

final class MaterialUiCatalog
{
    public const ACTIVITY_WOOD = 'Madera';
    public const ACTIVITY_PAPER = 'Papel';
    public const ACTIVITY_CARDBOARD = 'Cartón';
    public const ACTIVITY_METAL = 'Metal para estructuras (perfiles, rieles)';
    public const ACTIVITY_PLASTERBOARD = 'Placas de yeso';
    public const ACTIVITY_BATTERIES = 'Pilas, baterías';
    public const ACTIVITY_TEXTILES = 'Fibras textiles';
    public const ACTIVITY_CANVAS = 'Loneta, loneta';
    public const ACTIVITY_CARPET = 'Moqueta';
    public const ACTIVITY_PAINT = 'Pintura';
    public const ACTIVITY_VARNISH = 'Barniz';
    public const ACTIVITY_SOLVENT = 'Disolvente';
    public const ACTIVITY_CLOTHING = 'Ropa y accesorios';

    private const FACTOR_HEADERS = [
        'activity', 'subproduct', 'origin', 'unit', 'factor_value', 'factor_year',
        'factor_unit', 'source_file', 'source_sheet', 'factor_basis',
        'is_temporal_fallback', 'fallback_reason', 'temporal_type', 'factor_version',
        'source_row',
    ];

    /** @var list<array<string, string>> */
    private array $routes;
    /** @var array<string, string> */
    private array $solidWoodDensities;
    /** @var array<string, array<string, array<string, string>>> */
    private array $woodBoards;
    /** @var array<string, list<string>> */
    private array $batteryWeights;
    /** @var array<string, array{grammage: ?string, packageWeightKg: string, surfaceM2: ?string}> */
    private array $paperFormats;
    /** @var array<string, string> */
    private array $cardboardGrammages;
    /** @var array<string, string> */
    private array $densities;

    public function __construct(?string $dataDirectory = null)
    {
        $directory = $dataDirectory ?? dirname(__DIR__, 3).'/DataFixtures/data/emission';
        $this->routes = $this->loadRoutes(
            $directory.'/material_factors_v1.csv',
            $directory.'/material_rules_v1.csv',
        );
        $this->solidWoodDensities = $this->loadSolidWood($directory.'/material_wood_solid_v1.csv');
        $this->woodBoards = $this->loadWoodBoards($directory.'/material_wood_boards_v1.csv');
        $this->batteryWeights = $this->loadBatteryWeights($directory.'/material_battery_weights_v1.csv');
        [$this->paperFormats, $this->cardboardGrammages, $this->densities] = $this->loadConversions(
            $directory.'/material_conversion_tables_v1.csv',
        );
    }

    public function normalizeCountry(string $country): string
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{3}$/', $country)) {
            throw new \InvalidArgumentException(sprintf('Unsupported material country ISO3 "%s".', $country));
        }

        return $country;
    }

    public function hasActivity(string $activity): bool
    {
        foreach ($this->routes as $route) {
            if ($activity === $route['activity']) {
                return true;
            }
        }

        return false;
    }

    public function requiresSubproduct(string $activity): bool
    {
        foreach ($this->routes as $route) {
            if ($activity === $route['activity'] && '' !== $route['subproduct']) {
                return true;
            }
        }

        return false;
    }

    public function canonicalSubproduct(string $activity, ?string $subproduct): ?string
    {
        $subproduct = null === $subproduct ? '' : trim($subproduct);
        foreach ($this->routes as $route) {
            if ($activity === $route['activity'] && $subproduct === $route['subproduct']) {
                return $subproduct;
            }
        }

        return null;
    }

    public function canonicalOrigin(string $activity, ?string $subproduct, ?string $origin): ?string
    {
        $subproduct = null === $subproduct ? '' : $subproduct;
        $origin = null === $origin ? '' : trim($origin);
        foreach ($this->routes as $route) {
            if ($activity === $route['activity'] && $subproduct === $route['subproduct'] && $origin === $route['origin']) {
                return $origin;
            }
        }

        // A complete selection can legitimately lack a documented factor (for example reused plastic).
        return '' === $origin ? null : $origin;
    }

    /** @return array<string, string>|null */
    public function resolveRoute(string $activity, ?string $subproduct, string $origin, string $unit): ?array
    {
        $subproduct = null === $subproduct ? '' : $subproduct;
        foreach ($this->routes as $route) {
            if (
                $activity === $route['activity']
                && $subproduct === $route['subproduct']
                && $origin === $route['origin']
                && $unit === $route['unit']
            ) {
                return $route;
            }
        }

        return null;
    }

    public function solidWoodDensity(?string $type): ?string
    {
        return null === $type ? null : ($this->solidWoodDensities[$type] ?? null);
    }

    /** @return array<string, string>|null */
    public function woodBoard(?string $family, ?string $thickness): ?array
    {
        return null === $family || null === $thickness ? null : ($this->woodBoards[$family][$thickness] ?? null);
    }

    public function batteryWeight(?string $chemistry, ?string $size): ?string
    {
        if (null === $chemistry || null === $size) {
            return null;
        }
        $weights = $this->batteryWeights[$chemistry."\x1f".$size] ?? [];

        return 1 === count($weights) ? $weights[0] : null;
    }

    /** @return array{grammage: ?string, packageWeightKg: string, surfaceM2: ?string}|null */
    public function paperFormat(?string $format): ?array
    {
        return null === $format ? null : ($this->paperFormats[$format] ?? null);
    }

    public function cardboardGrammage(?string $type): ?string
    {
        return null === $type ? null : ($this->cardboardGrammages[$type] ?? null);
    }

    public function density(string $activity): ?string
    {
        return $this->densities[$activity] ?? null;
    }

    public function isPlastic(string $activity): bool
    {
        return str_contains(mb_strtolower($activity), 'plástic');
    }

    /** @return list<array<string, string>> */
    private function loadRoutes(string $factorFile, string $ruleFile): array
    {
        $routes = [];
        foreach ([$factorFile, $ruleFile] as $path) {
            foreach ($this->csvRows($path, self::FACTOR_HEADERS) as $row) {
                if (!in_array($row['temporal_type'], [
                    EmissionFactor::TEMPORAL_TYPE_ANNUAL,
                    EmissionFactor::TEMPORAL_TYPE_VERSIONED,
                    EmissionFactor::TEMPORAL_TYPE_RULE,
                ], true)) {
                    throw new \RuntimeException(sprintf('Unsupported material temporal type "%s".', $row['temporal_type']));
                }
                $identity = implode("\x1f", [$row['activity'], $row['subproduct'], $row['origin'], $row['unit']]);
                if (isset($routes[$identity]) && $routes[$identity]['temporal_type'] !== $row['temporal_type']) {
                    throw new \RuntimeException('A material route declares incompatible temporal types.');
                }
                $routes[$identity] = [
                    'activity' => $row['activity'],
                    'subproduct' => $row['subproduct'],
                    'origin' => $row['origin'],
                    'unit' => $row['unit'],
                    'temporal_type' => $row['temporal_type'],
                ];
            }
        }
        if (400 !== count($routes)) {
            throw new \RuntimeException(sprintf('Expected 400 logical material routes, got %d.', count($routes)));
        }

        return array_values($routes);
    }

    /** @return array<string, string> */
    private function loadSolidWood(string $path): array
    {
        $result = [];
        foreach ($this->csvRows($path, ['tipo', 'densidad_kg_m3', 'source_row']) as $row) {
            $result[$row['tipo']] = $row['densidad_kg_m3'];
        }

        return $result;
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function loadWoodBoards(string $path): array
    {
        $result = [];
        foreach ($this->csvRows($path, ['familia', 'grosor', 'grosor_m', 'largo_m', 'ancho_m', 'densidad_kg_m3', 'source_row']) as $row) {
            $result[$row['familia']][$row['grosor']] = $row;
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private function loadBatteryWeights(string $path): array
    {
        $result = [];
        foreach ($this->csvRows($path, ['tamaño', 'química', 'peso_kg', 'source_row']) as $row) {
            $key = $row['química']."\x1f".$row['tamaño'];
            $result[$key][$row['peso_kg']] = true;
        }

        return array_map(static fn (array $weights): array => array_keys($weights), $result);
    }

    /** @return array{array<string, array{grammage: ?string, packageWeightKg: string, surfaceM2: ?string}>, array<string, string>, array<string, string>} */
    private function loadConversions(string $path): array
    {
        $paper = [];
        $cardboard = [];
        $densities = [];
        foreach ($this->csvRows($path, ['grupo', 'opción', 'valor_1', 'valor_2', 'nota', 'source_row']) as $row) {
            if ('Cartón tipo caja' === $row['grupo']) {
                $cardboard[$row['opción']] = $row['valor_1'];
            } elseif ('Papel formato' === $row['grupo']) {
                $surface = null;
                if (preg_match('/\((\d+) x (\d+)\)/', $row['opción'], $matches)) {
                    $surface = $this->multiply($this->divide($matches[1], '1000'), $this->divide($matches[2], '1000'));
                }
                $paper[$row['opción']] = [
                    'grammage' => '' === $row['valor_1'] ? null : $row['valor_1'],
                    'packageWeightKg' => $row['valor_2'],
                    'surfaceM2' => $surface,
                ];
            } elseif ('densidad predeterminada' === $row['opción']) {
                $densities[$row['grupo']] = $row['valor_1'];
            }
        }

        return [$paper, $cardboard, $densities];
    }

    /** @param list<string> $headers
     *  @return list<array<string, string>>
     */
    private function csvRows(string $path, array $headers): array
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if ($headers !== $file->fgetcsv()) {
            throw new \RuntimeException(sprintf('Unexpected material CSV headers in "%s".', basename($path)));
        }
        $rows = [];
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count($headers) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed material CSV row %d in "%s".', $file->key() + 1, basename($path)));
            }
            /** @var array<string, string> $row */
            $row = array_combine($headers, $values);
            $rows[] = $row;
        }

        return $rows;
    }

    private function multiply(string $left, string $right): string
    {
        return $this->trimDecimal(bcmul($left, $right, 18));
    }

    private function divide(string $left, string $right): string
    {
        return $this->trimDecimal(bcdiv($left, $right, 18));
    }

    private function trimDecimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
