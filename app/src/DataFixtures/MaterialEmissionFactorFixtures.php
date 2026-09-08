<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class MaterialEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'material';
    private const FACTOR_FILE = __DIR__.'/data/emission/material_factors_v1.csv';
    private const RULE_FILE = __DIR__.'/data/emission/material_rules_v1.csv';
    private const HEADERS = [
        'activity', 'subproduct', 'origin', 'unit', 'factor_value', 'factor_year',
        'factor_unit', 'source_file', 'source_sheet', 'factor_basis',
        'is_temporal_fallback', 'fallback_reason', 'temporal_type', 'factor_version',
        'source_row',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'material-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $identities = [];
        $counts = [
            EmissionFactor::TEMPORAL_TYPE_ANNUAL => 0,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED => 0,
            EmissionFactor::TEMPORAL_TYPE_RULE => 0,
        ];
        $this->loadFile(self::FACTOR_FILE, $manager, $identities, $counts);
        $this->loadFile(self::RULE_FILE, $manager, $identities, $counts);

        if (135 !== $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL]) {
            throw new \RuntimeException(sprintf('Expected 135 ANNUAL material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL]));
        }
        if (187 !== $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED]) {
            throw new \RuntimeException(sprintf('Expected 187 VERSIONED material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED]));
        }
        if (186 !== $counts[EmissionFactor::TEMPORAL_TYPE_RULE]) {
            throw new \RuntimeException(sprintf('Expected 186 RULE material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_RULE]));
        }

        $manager->flush();
    }

    /** @param array<string, true> $identities
     *  @param array<string, int> $counts
     */
    private function loadFile(string $path, ObjectManager $manager, array &$identities, array &$counts): void
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException(sprintf('Unexpected material CSV headers in "%s".', basename($path)));
        }

        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed material CSV row %d in "%s".', $file->key() + 1, basename($path)));
            }
            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            $temporalType = $row['temporal_type'];
            if (!array_key_exists($temporalType, $counts)) {
                throw new \RuntimeException(sprintf('Unsupported material temporal type in CSV row %d.', $file->key() + 1));
            }
            if ('' === $row['factor_value'] || !is_numeric($row['factor_value'])) {
                throw new \RuntimeException(sprintf('Invalid material factor value in CSV row %d.', $file->key() + 1));
            }

            $year = null;
            if (EmissionFactor::TEMPORAL_TYPE_ANNUAL === $temporalType) {
                if (!preg_match('/^20(?:22|23|24|25|26)$/', $row['factor_year'])) {
                    throw new \RuntimeException(sprintf('Invalid annual material factor year in CSV row %d.', $file->key() + 1));
                }
                $year = (int) $row['factor_year'];
            } elseif ('' !== $row['factor_year']) {
                throw new \RuntimeException(sprintf('Methodological material factors must not define a year in CSV row %d.', $file->key() + 1));
            }
            if (EmissionFactor::TEMPORAL_TYPE_RULE === $temporalType && '0' !== $row['factor_value']) {
                throw new \RuntimeException(sprintf('Material RULE must define explicit zero in CSV row %d.', $file->key() + 1));
            }

            $criteria = $this->keyGenerator->normalize([
                'activity' => $row['activity'],
                'subproduct' => $row['subproduct'],
                'origin' => $row['origin'],
                'unit' => $row['unit'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $identity = $functionalKey.'|'.$temporalType.'|'.($year ?? 'NULL');
            if (isset($identities[$identity])) {
                throw new \RuntimeException(sprintf('Duplicate material factor identity in CSV row %d.', $file->key() + 1));
            }
            $identities[$identity] = true;

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setYear($year)
                ->setTemporalType($temporalType)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source_file'])
                ->setSourceDetail($row['source_sheet'])
                ->setMetadata([
                    'activity' => $row['activity'],
                    'subproduct' => '' === $row['subproduct'] ? null : $row['subproduct'],
                    'origin' => $row['origin'],
                    'activityUnit' => $row['unit'],
                    'sourceFile' => $row['source_file'],
                    'sourceSheet' => $row['source_sheet'],
                    'factorBasis' => $row['factor_basis'],
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'sourceRow' => (int) $row['source_row'],
                    'sourceFallbackNote' => '' === $row['fallback_reason'] ? null : $row['fallback_reason'],
                ]));
            ++$counts[$temporalType];
        }
    }
}
