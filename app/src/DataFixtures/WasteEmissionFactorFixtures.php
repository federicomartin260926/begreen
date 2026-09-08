<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class WasteEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'waste';
    private const FACTOR_FILE = __DIR__.'/data/emission/waste_factors_v1.csv';
    private const RULE_FILE = __DIR__.'/data/emission/waste_rules_v1.csv';
    private const FACTOR_HEADERS = [
        'region_scope', 'region_label', 'waste_type', 'waste_activity', 'treatment',
        'activity_unit', 'factor_value', 'factor_year', 'factor_version',
        'source_family', 'temporal_type', 'status', 'notes', 'source_row',
        'activity_year_scope',
    ];
    private const RULE_HEADERS = [
        'region_scope', 'region_label', 'waste_type', 'waste_activity',
        'treatment', 'rule_type', 'value', 'detail', 'source_row',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'waste-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $identities = [];
        $factorCount = $this->loadConventionalFactors($manager, $identities);
        $ruleCount = $this->loadMethodologicalRules($manager, $identities);

        if (526 !== $factorCount) {
            throw new \RuntimeException(sprintf('Expected 526 conventional waste emission factors, got %d.', $factorCount));
        }
        if (25 !== $ruleCount) {
            throw new \RuntimeException(sprintf('Expected 25 NON_WASTE_ROUTE_ZERO rules, got %d.', $ruleCount));
        }

        $manager->flush();
    }

    /** @param array<string, true> $identities */
    private function loadConventionalFactors(ObjectManager $manager, array &$identities): int
    {
        $file = $this->csv(self::FACTOR_FILE, self::FACTOR_HEADERS, 'waste factor');
        $count = 0;

        while (!$file->eof()) {
            $row = $this->row($file, self::FACTOR_HEADERS, 'waste factor');
            if (null === $row) {
                continue;
            }

            $this->assertRegionScope($row['region_scope']);
            if ('kg' !== $row['activity_unit']) {
                throw new \RuntimeException(sprintf('Unexpected waste activity unit in CSV row %d.', $file->key() + 1));
            }
            if ('' === $row['factor_value'] || !is_numeric($row['factor_value'])) {
                throw new \RuntimeException(sprintf('Invalid waste factor value in CSV row %d.', $file->key() + 1));
            }
            if (!in_array($row['temporal_type'], [
                EmissionFactor::TEMPORAL_TYPE_ANNUAL,
                EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            ], true)) {
                throw new \RuntimeException(sprintf('Unsupported waste factor temporal type in CSV row %d.', $file->key() + 1));
            }

            $year = null;
            if (EmissionFactor::TEMPORAL_TYPE_ANNUAL === $row['temporal_type']) {
                if ('DEFRA' !== $row['source_family'] || !preg_match('/^\d{4}$/', $row['factor_year'])) {
                    throw new \RuntimeException(sprintf('Invalid annual DEFRA waste factor in CSV row %d.', $file->key() + 1));
                }
                $year = (int) $row['factor_year'];
            } else {
                if ('OCCC' !== $row['source_family'] || '' !== $row['factor_year']) {
                    throw new \RuntimeException(sprintf('Invalid VERSIONED OCCC waste factor in CSV row %d.', $file->key() + 1));
                }
            }

            $criteria = $this->keyGenerator->normalize([
                'regionScope' => $row['region_scope'],
                'wasteType' => $row['waste_type'],
                'wasteActivity' => $row['waste_activity'],
                'treatment' => $row['treatment'],
                'unit' => 'kg',
                'sourceFamily' => $row['source_family'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUnique($identities, $functionalKey, $row['temporal_type'], $year, $file->key() + 1);

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setYear($year)
                ->setTemporalType($row['temporal_type'])
                ->setValue($row['factor_value'])
                ->setUnit('kgCO2e/kg')
                ->setSource($row['source_family'])
                ->setSourceDetail('' === $row['factor_version'] ? null : $row['factor_version'])
                ->setMetadata([
                    'regionLabel' => $row['region_label'],
                    'wasteType' => $row['waste_type'],
                    'wasteActivity' => $row['waste_activity'],
                    'treatment' => $row['treatment'],
                    'sourceFamily' => $row['source_family'],
                    'sourceGeography' => 'OCCC' === $row['source_family'] ? 'ESP' : 'GBR',
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'status' => $row['status'],
                    'notes' => $row['notes'],
                    'sourceRow' => (int) $row['source_row'],
                    'activityYearScope' => $row['activity_year_scope'],
                    'activityUnit' => 'kg',
                ]));
            ++$count;
        }

        return $count;
    }

    /** @param array<string, true> $identities */
    private function loadMethodologicalRules(ObjectManager $manager, array &$identities): int
    {
        $file = $this->csv(self::RULE_FILE, self::RULE_HEADERS, 'waste rule');
        $count = 0;

        while (!$file->eof()) {
            $row = $this->row($file, self::RULE_HEADERS, 'waste rule');
            if (null === $row || 'NON_WASTE_ROUTE_ZERO' !== $row['rule_type']) {
                continue;
            }

            $this->assertRegionScope($row['region_scope']);
            if ('0' !== $row['value']) {
                throw new \RuntimeException(sprintf('NON_WASTE_ROUTE_ZERO must define zero in CSV row %d.', $file->key() + 1));
            }

            $criteria = $this->keyGenerator->normalize([
                'regionScope' => $row['region_scope'],
                'wasteType' => $row['waste_type'],
                'wasteActivity' => $row['waste_activity'],
                'treatment' => $row['treatment'],
                'unit' => 'kg',
                'ruleType' => 'NON_WASTE_ROUTE_ZERO',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUnique(
                $identities,
                $functionalKey,
                EmissionFactor::TEMPORAL_TYPE_RULE,
                null,
                $file->key() + 1,
            );

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setYear(null)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_RULE)
                ->setValue('0')
                ->setUnit('kgCO2e/kg')
                ->setSource('BGMF Residuos specification')
                ->setSourceDetail($row['detail'])
                ->setMetadata([
                    'regionLabel' => $row['region_label'],
                    'wasteType' => $row['waste_type'],
                    'wasteActivity' => $row['waste_activity'],
                    'treatment' => $row['treatment'],
                    'ruleType' => 'NON_WASTE_ROUTE_ZERO',
                    'sourceRow' => '' === $row['source_row'] ? null : (int) $row['source_row'],
                    'activityUnit' => 'kg',
                ]));
            ++$count;
        }

        return $count;
    }

    /** @param list<string> $headers */
    private function csv(string $path, array $headers, string $label): \SplFileObject
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if ($headers !== $file->fgetcsv()) {
            throw new \RuntimeException(sprintf('Unexpected %s CSV headers.', $label));
        }

        return $file;
    }

    /** @param list<string> $headers
     *  @return array<string, string>|null
     */
    private function row(\SplFileObject $file, array $headers, string $label): ?array
    {
        $values = $file->fgetcsv();
        if (false === $values || [null] === $values) {
            return null;
        }
        if (count($headers) !== count($values)) {
            throw new \RuntimeException(sprintf('Malformed %s CSV row %d.', $label, $file->key() + 1));
        }

        /** @var array<string, string> $row */
        $row = array_combine($headers, $values);

        return $row;
    }

    private function assertRegionScope(string $regionScope): void
    {
        if (!in_array($regionScope, ['spain', 'outside_spain'], true)) {
            throw new \RuntimeException(sprintf('Unsupported waste region scope "%s".', $regionScope));
        }
    }

    /** @param array<string, true> $identities */
    private function assertUnique(
        array &$identities,
        string $functionalKey,
        string $temporalType,
        ?int $year,
        int $row,
    ): void {
        $identity = $functionalKey.'|'.$temporalType.'|'.(null === $year ? 'NULL' : (string) $year);
        if (isset($identities[$identity])) {
            throw new \RuntimeException(sprintf('Duplicate waste factor identity in CSV row %d.', $row));
        }
        $identities[$identity] = true;
    }
}
