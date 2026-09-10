<?php

declare(strict_types=1);

namespace App\Service\Emission;

use Symfony\Component\Intl\Countries;

final class EmissionCountryCatalog
{
    private const HEADERS = ['country', 'iso3', 'region_residuos'];

    /** @var array<string, array{country: string, region_residuos: string}> */
    private array $countries;

    public function __construct(?string $catalogFile = null)
    {
        $this->countries = $this->load($catalogFile ?? dirname(__DIR__, 2).'/DataFixtures/data/emission/countries_v1.csv');
    }

    public function normalizeIso3(string $iso3): string
    {
        $iso3 = mb_strtoupper(trim($iso3), 'UTF-8');
        if (!isset($this->countries[$iso3])) {
            throw new \InvalidArgumentException(sprintf('Unsupported emission country ISO3 "%s".', $iso3));
        }

        return $iso3;
    }

    public function name(string $iso3): string
    {
        return $this->countries[$this->normalizeIso3($iso3)]['country'];
    }

    public function wasteRegion(string $iso3): string
    {
        return $this->countries[$this->normalizeIso3($iso3)]['region_residuos'];
    }

    /** @return array<string, string> */
    public function choices(string $locale = 'es'): array
    {
        $localized = str_starts_with(strtolower($locale), 'es') ? [] : Countries::getAlpha3Names($locale);
        $choices = [];
        foreach ($this->countries as $iso3 => $country) {
            $choices[$iso3] = $localized[$iso3] ?? $country['country'];
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    public function iso2FromIso3(string $iso3): string
    {
        return Countries::getAlpha2Code($this->normalizeIso3($iso3));
    }

    public function iso3FromIso2(string $iso2): string
    {
        $iso2 = mb_strtoupper(trim($iso2), 'UTF-8');
        if (!Countries::exists($iso2)) {
            throw new \InvalidArgumentException(sprintf('Unsupported emission country ISO2 "%s".', $iso2));
        }

        return $this->normalizeIso3(Countries::getAlpha3Code($iso2));
    }

    public function iso3ForForm(string $country): string
    {
        try {
            return 2 === strlen(trim($country)) ? $this->iso3FromIso2($country) : $this->normalizeIso3($country);
        } catch (\InvalidArgumentException) {
            return $country;
        }
    }

    /** @return array<string, array{country: string, region_residuos: string}> */
    private function load(string $path): array
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected emission country CSV headers.');
        }

        $countries = [];
        $names = [];
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (3 !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed emission country CSV row %d.', $file->key() + 1));
            }
            [$country, $iso3, $region] = array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values);
            if ('' === $country || '' === $region || 1 !== preg_match('/^[A-Z]{3}$/', $iso3)) {
                throw new \RuntimeException(sprintf('Invalid emission country CSV row %d.', $file->key() + 1));
            }
            if (isset($countries[$iso3]) || isset($names[$country])) {
                throw new \RuntimeException(sprintf('Duplicate emission country CSV row %d.', $file->key() + 1));
            }
            if (in_array($iso3, ['ANT', 'XKX'], true)) {
                continue;
            }

            $countries[$iso3] = ['country' => $country, 'region_residuos' => $region];
            $names[$country] = true;
        }
        if (215 !== count($countries)) {
            throw new \RuntimeException(sprintf('Expected 215 supported emission countries, got %d.', count($countries)));
        }

        return $countries;
    }
}
