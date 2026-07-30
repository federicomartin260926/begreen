<?php

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class GamificationCatalogTest extends TestCase
{
    public function testSpanishAndEnglishCataloguesHaveCompleteMatchingKeys(): void
    {
        $spanish = $this->flatten(Yaml::parseFile(dirname(__DIR__, 2) . '/translations/gamification.es.yaml'));
        $english = $this->flatten(Yaml::parseFile(dirname(__DIR__, 2) . '/translations/gamification.en.yaml'));

        self::assertCount(100, $spanish);
        self::assertSame(array_keys($spanish), array_keys($english));
        self::assertNotContains('', array_map('trim', $spanish));
        self::assertNotContains('', array_map('trim', $english));

        $groups = [
            'welcome.seed', 'welcome.plant', 'welcome.tree', 'welcome.forest', 'welcome.jungle',
            'level_up.plant', 'level_up.tree', 'level_up.forest', 'level_up.jungle',
            'completed_100',
        ];

        foreach ($groups as $group) {
            $messages = array_filter(
                $spanish,
                static fn (string $key): bool => str_starts_with($key, $group . '.'),
                ARRAY_FILTER_USE_KEY
            );
            self::assertCount(10, $messages, sprintf('Unexpected size for "%s".', $group));
            self::assertSame(
                count($messages),
                count(array_unique($messages)),
                sprintf('Duplicate Spanish messages found in "%s".', $group)
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $path = ltrim($prefix . '.' . (string) $key, '.');
            if (is_array($value)) {
                $result += $this->flatten($value, $path);
                continue;
            }

            self::assertIsString($value);
            $result[$path] = $value;
        }

        return $result;
    }
}
