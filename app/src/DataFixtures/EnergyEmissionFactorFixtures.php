<?php

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Source: Energia.xlsx, FINAL v10 delivery, received 2026-09-04.
 * Materialized activity-year fallback rows are excluded from the normalized CSV.
 */
final class EnergyEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'energy';
    private const DATA_FILE = __DIR__.'/data/emission/energy_factors_v1.csv';
    private const HEADERS = [
        'geography', 'category', 'activity', 'labeling', 'supplier', 'unit', 'factor_year',
        'factor_value', 'factor_unit', 'source', 'source_detail', 'scope',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'energy-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $file = new \SplFileObject(self::DATA_FILE, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected energy emission factor CSV headers.');
        }

        $identities = [];
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed energy emission factor CSV row %d.', $file->key() + 1));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            $criteria = $this->keyGenerator->normalize([
                'geography' => $row['geography'],
                'category' => $row['category'],
                'activity' => $row['activity'],
                'labeling' => $row['labeling'],
                'supplier' => $row['supplier'],
                'unit' => $row['unit'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $identity = $functionalKey.'|'.$row['factor_year'];
            if (isset($identities[$identity])) {
                throw new \RuntimeException(sprintf('Duplicate energy factor source identity in CSV row %d.', $file->key() + 1));
            }
            $identities[$identity] = true;

            $factor = (new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
                ->setYear((int) $row['factor_year'])
                ->setValue('' === $row['factor_value'] ? null : $row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'activityUnit' => $row['unit'],
                    'scope' => $row['scope'],
                    'sourceUrl' => $this->sourceUrl($row['source']),
                    'factorVersion' => 'Energia FINAL v10 · 2026-09-04',
                ]);
            $manager->persist($factor);
        }

        $manager->flush();
    }

    private function sourceUrl(string $source): ?string
    {
        return match ($source) {
            'MITECO' => 'https://www.miteco.gob.es/',
            'DEFRA' => 'https://www.gov.uk/government/publications/greenhouse-gas-reporting-conversion-factors-2026',
            default => null,
        };
    }
}
